<?php

declare(strict_types=1);

namespace App\Dte\Hacienda;

use App\Dte\Eventos\GestorContingencia;
use App\Dte\Excepciones\DteException;
use App\Dte\Firma\FirmadorClient;
use App\Dte\Validacion\ValidadorEsquema;
use App\Enums\EstadoDte;
use App\Jobs\EntregarDte;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\EmpresaCredencial;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;

/**
 * Lleva un DTE de «borrador/validado» a «transmitido» (o rechazado / transitorio).
 *
 * Política de reintentos (MTIST 3.3):
 *   1. Enviar con timeout de 8 s.
 *   2. Si no hay respuesta: consultar si llegó.
 *   3. Si no llegó: reenviar, como máximo 2 veces (mismo codigoGeneracion).
 *   4. Si sigue sin respuesta: contingencia (estado «transitorio»; el evento se envía en el Paso 7).
 */
final class TransmisorDte
{
    public function __construct(
        private readonly FirmadorClient $firmador,
        private readonly ValidadorEsquema $validador,
    ) {}

    public static function crear(): self
    {
        return new self(new FirmadorClient, new ValidadorEsquema((string) config('dte.esquemas')));
    }

    public function procesar(Dte $dte, Empresa $empresa, EmpresaCredencial $credencial): Dte
    {
        if ($credencial->ambiente !== $dte->ambiente) {
            // MTIST 6.3: jamás enviar documentos de pruebas a producción (ni al revés).
            throw new LogicException("El DTE es del ambiente {$dte->ambiente} y las credenciales del {$credencial->ambiente}.");
        }

        if (in_array($dte->estado, [EstadoDte::Borrador, EstadoDte::Validado], true)) {
            $this->firmar($dte, $empresa, $credencial);
        }

        if (! in_array($dte->estado, [EstadoDte::Firmado, EstadoDte::Enviado, EstadoDte::Rechazado, EstadoDte::Transitorio], true)) {
            throw new LogicException("Un DTE en estado {$dte->estado->value} no se transmite.");
        }

        $contingencias = app(GestorContingencia::class);

        // Con una contingencia abierta no se insiste con Hacienda: el documento entra directo a la contingencia.
        if ($contingencias->activa($credencial->ambiente) !== null && $contingencias->admite($dte->tipo)) {
            $dte->transicionar(EstadoDte::Transitorio, 'Emitido durante una contingencia activa');
            $contingencias->registrar($dte, $empresa, $credencial);

            return $dte;
        }

        $this->transmitir($dte, new HaciendaClient($empresa, $credencial));

        if ($dte->estado === EstadoDte::Transitorio) {
            if ($contingencias->admite($dte->tipo)) {
                $contingencias->registrar($dte, $empresa, $credencial);
            } else {
                // CL, DCL, CD, NC/ND sobre CR… no se emiten en contingencia: se reintentará el envío normal.
                $dte->transicionar(EstadoDte::Firmado, 'Este tipo de documento no admite contingencia: se reintentará el envío');
            }
        }

        return $dte;
    }

    public function firmar(Dte $dte, Empresa $empresa, EmpresaCredencial $credencial): void
    {
        // Antes de firmar, todo JSON se valida contra su esquema oficial.
        $errores = $this->validador->validar($dte->json_texto, $dte->tipo, $dte->version);
        if ($errores !== []) {
            throw new DteException('El documento no cumple el esquema oficial de Hacienda: '.implode('; ', array_map(
                fn ($ruta, $mensajes) => $ruta.': '.implode(', ', $mensajes), array_keys($errores), $errores,
            )));
        }
        if ($dte->estado === EstadoDte::Borrador) {
            $dte->transicionar(EstadoDte::Validado, 'Cumple el esquema oficial');
        }

        $jws = $this->firmador->firmar($empresa->nit, $credencial->pwd_privada, $dte->json_texto);
        $dte->transicionar(EstadoDte::Firmado, 'Firmado con el firmador oficial', ['jws' => $jws]);
    }

    public function transmitir(Dte $dte, HaciendaClient $hacienda): Dte
    {
        $maxReenvios = (int) config('dte.api.reintentos');
        $reenvios = 0;

        while (true) {
            $idEnvio = $dte->envios + 1;
            $dte->transicionar(EstadoDte::Enviado, $reenvios === 0 ? 'Enviado a Hacienda' : "Reenvío {$reenvios} de {$maxReenvios}", ['envios' => $idEnvio], ['idEnvio' => $idEnvio]);

            try {
                $respuesta = $hacienda->recibirDte((string) $dte->jws, $dte->tipo, $dte->version, $dte->codigo_generacion, $idEnvio);

                return $this->aplicarRespuesta($dte, $respuesta, 'Respuesta de recepción');
            } catch (SinRespuestaException $e) {
                $this->anotar($dte, 'Sin respuesta de Hacienda: '.$e->getMessage());
            }

            // ¿Llegó aunque no hubo respuesta? Consultar antes de reenviar.
            try {
                $consulta = $hacienda->consultarDte($dte->tipo, $dte->codigo_generacion);
                if ($consulta !== null) {
                    return $this->aplicarRespuesta($dte, $consulta, 'Encontrado por consulta');
                }
            } catch (SinRespuestaException) {
                // Si la consulta tampoco responde, se trata como «no llegó».
            }

            if ($reenvios >= $maxReenvios) {
                $dte->transicionar(EstadoDte::Transitorio, "Sin respuesta tras {$maxReenvios} reenvíos: pasa a contingencia");

                return $dte;
            }
            $reenvios++;
        }
    }

    /**
     * @param  array<string, mixed>  $r
     */
    public function aplicarRespuesta(Dte $dte, array $r, string $origen): Dte
    {
        $estado = $r['estado'] ?? null;
        $observaciones = array_values(array_filter(
            is_array($r['observaciones'] ?? null) ? $r['observaciones'] : [],
            fn ($o) => is_string($o) && trim($o) !== '',
        ));

        if ($estado === 'PROCESADO' && is_string($r['selloRecibido'] ?? null) && $r['selloRecibido'] !== '') {
            $conObservaciones = $observaciones !== [] || ltrim((string) ($r['codigoMsg'] ?? ''), '0') === '2';
            $dte->transicionar(
                $conObservaciones ? EstadoDte::Observado : EstadoDte::Transmitido,
                $origen.': '.MensajesHacienda::traducir($r['codigoMsg'] ?? null, $r['descripcionMsg'] ?? null),
                [
                    'sello_recibido' => $r['selloRecibido'],
                    'fh_procesamiento' => $this->fecha($r['fhProcesamiento'] ?? null),
                    'respuesta_mh' => $r,
                    'observaciones_mh' => $observaciones === [] ? null : $observaciones,
                ],
                respuesta: $r,
            );
            self::entregar($dte);

            return $dte;
        }

        if ($estado === 'RECHAZADO') {
            $dte->transicionar(
                EstadoDte::Rechazado,
                MensajesHacienda::traducir(isset($r['codigoMsg']) ? (string) $r['codigoMsg'] : null, isset($r['descripcionMsg']) ? (string) $r['descripcionMsg'] : null),
                ['respuesta_mh' => $r, 'observaciones_mh' => $observaciones === [] ? null : $observaciones],
                respuesta: $r,
            );

            return $dte;
        }

        throw new HaciendaException('Hacienda devolvió una respuesta inesperada (estado: '.json_encode($estado).').');
    }

    /** Archiva y entrega al receptor (cola «entrega»), si la entrega automática está activa. */
    public static function entregar(Dte $dte): void
    {
        if (config('dte.entrega.automatica')) {
            EntregarDte::dispatch($dte->empresa_id, $dte->id)->afterCommit();
        }
    }

    private function anotar(Dte $dte, string $motivo): void
    {
        $dte->eventosInternos()->create([
            'empresa_id' => $dte->empresa_id, 'de' => $dte->estado->value, 'a' => $dte->estado->value, 'motivo' => $motivo,
        ]);
    }

    private function fecha(mixed $texto): ?DateTimeImmutable
    {
        if (! is_string($texto)) {
            return null;
        }
        $fecha = DateTimeImmutable::createFromFormat('d/m/Y H:i:s', $texto, new DateTimeZone((string) config('dte.zona_horaria')));

        return $fecha === false ? null : $fecha;
    }
}
