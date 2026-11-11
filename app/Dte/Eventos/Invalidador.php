<?php

declare(strict_types=1);

namespace App\Dte\Eventos;

use App\Dte\Excepciones\DteException;
use App\Dte\Hacienda\HaciendaClient;
use App\Dte\Hacienda\MensajesHacienda;
use App\Dte\Support\CodigoGeneracion;
use App\Enums\EstadoDte;
use App\Models\Bitacora;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\EmpresaCredencial;
use App\Models\Evento;
use App\Models\PuntoVenta;
use App\Support\DteJson;
use Carbon\CarbonImmutable;

/**
 * Evento de Invalidación (Normativa 13.1, Cuadro 6; esquema invalidacion v3).
 *
 *  - Solo se invalida lo que tiene sello, y dentro del plazo (si no: «FECHA FUERA DE PLAZO PERMITIDO»).
 *  - Tipos 1 y 3: primero se emite el documento que reemplaza (salvo NC y CL) y el evento lo referencia.
 *  - Tipo 2 (rescisión): sin documento que reemplaza.
 *  - Error ≠ afectación: un cambio de cantidad o precio en CCF/CR se corrige con NC/ND, no invalidando.
 */
final class Invalidador
{
    public const VERSION = 3;

    private const SIN_REEMPLAZO_OBLIGATORIO = ['05', '08'];

    public function __construct(
        private readonly Plazos $plazos = new Plazos,
        private readonly FirmaEventos $firma = new FirmaEventos,
    ) {}

    /** Valida y arma el JSON del evento (sin firmar). */
    public function armar(Dte $dte, Empresa $empresa, DatosInvalidacion $datos, CarbonImmutable $momento, ?string $codigoGeneracion = null): string
    {
        $this->validar($dte, $datos, $momento);

        $original = $dte->json_original;
        $receptor = $original['receptor'] ?? null;
        $establecimiento = $dte->establecimiento()->firstOrFail();
        $puntoVenta = PuntoVenta::query()->findOrFail($dte->punto_venta_id);
        $ahora = $momento->setTimezone(Plazos::zona());

        $documento = [
            'identificacion' => [
                'version' => self::VERSION,
                'ambiente' => $dte->ambiente,
                'codigoGeneracion' => $codigoGeneracion ?? CodigoGeneracion::nuevo(),
                'fecEmi' => $ahora->format('Y-m-d'),
                'horEmi' => $ahora->format('H:i:s'),
                'fusion' => null,
            ],
            'emisor' => [
                'nit' => $empresa->nit,
                'nombre' => $empresa->razon_social,
                'codEstableMH' => $establecimiento->cod_estable_mh,
                'codEstable' => $establecimiento->cod_estable,
                'codPuntoVentaMH' => $puntoVenta->cod_punto_venta_mh,
                'codPuntoVenta' => $puntoVenta->cod_punto_venta,
                'telefono' => (string) ($empresa->getAttribute('telefono') ?? ''),
                'correo' => (string) ($empresa->getAttribute('correo') ?? ''),
            ],
            'documento' => [
                'tipoDte' => $dte->tipo,
                'codigoGeneracion' => $dte->codigo_generacion,
                'selloRecibido' => $dte->sello_recibido,
                'numeroControl' => $dte->numero_control,
                'fecEmi' => $dte->fecha_emision->format('Y-m-d'),
                'codigoGeneracionR' => $datos->codigoGeneracionR,
                // FE < $25,000 sin receptor: no se exige (Normativa 13.1.1).
                'tipoDocumento' => $receptor['tipoDocumento'] ?? (isset($receptor['nit']) ? '36' : null),
                'numDocumento' => $receptor['numDocumento'] ?? $receptor['nit'] ?? null,
                'nombre' => $receptor['nombre'] ?? null,
                'telefono' => $receptor['telefono'] ?? null,
                'correo' => $receptor['correo'] ?? null,
            ],
            'motivo' => [
                'tipoAnulacion' => $datos->tipoAnulacion,
                'motivoAnulacion' => $datos->motivoAnulacion,
                'nombreResponsable' => $datos->nombreResponsable,
                'tipDocResponsable' => $datos->tipDocResponsable,
                'numDocResponsable' => $datos->numDocResponsable,
                'nombreSolicita' => $datos->nombreSolicita,
                'tipDocSolicita' => $datos->tipDocSolicita,
                'numDocSolicita' => $datos->numDocSolicita,
            ],
        ];

        return DteJson::encode($documento);
    }

    /** Arma, firma, transmite y, con sello, deja el DTE original en «invalidado». */
    public function invalidar(Dte $dte, Empresa $empresa, EmpresaCredencial $credencial, DatosInvalidacion $datos, ?CarbonImmutable $momento = null): Evento
    {
        $momento ??= CarbonImmutable::now(Plazos::zona());
        $json = $this->armar($dte, $empresa, $datos, $momento);
        $evento = $this->firma->firmarYGuardar($empresa, $credencial, Evento::INVALIDACION, self::VERSION, $json, $dte->id);

        $respuesta = (new HaciendaClient($empresa, $credencial))->enviarInvalidacion((string) $evento->jws, self::VERSION, $evento->id);
        $sello = $respuesta['selloRecibido'] ?? null;

        if (($respuesta['estado'] ?? null) === 'PROCESADO' && is_string($sello) && $sello !== '') {
            $evento->forceFill(['estado' => 'procesado', 'sello_recibido' => $sello, 'respuesta_mh' => $respuesta, 'fh_procesamiento' => now()])->save();
            $dte->transicionar(EstadoDte::Invalidado, 'Invalidado con el evento '.$evento->codigo_generacion, respuesta: $respuesta);
            Bitacora::registrar('dte.invalidado', $dte, ['evento' => $evento->codigo_generacion, 'tipoAnulacion' => $datos->tipoAnulacion]);

            return $evento;
        }

        $evento->forceFill(['estado' => 'rechazado', 'respuesta_mh' => $respuesta])->save();
        throw new DteException('Hacienda rechazó la invalidación: '.MensajesHacienda::traducir(
            isset($respuesta['codigoMsg']) ? (string) $respuesta['codigoMsg'] : null,
            isset($respuesta['descripcionMsg']) ? (string) $respuesta['descripcionMsg'] : null,
        ));
    }

    private function validar(Dte $dte, DatosInvalidacion $datos, CarbonImmutable $momento): void
    {
        if (! in_array($dte->estado, [EstadoDte::Transmitido, EstadoDte::Observado, EstadoDte::Ajustado], true) || $dte->sello_recibido === null) {
            throw new DteException('Solo se puede invalidar un documento con sello de recepción.');
        }
        if (! in_array($datos->tipoAnulacion, [1, 2, 3], true)) {
            throw new DteException('Tipo de invalidación inválido (CAT-024: 1 error, 2 rescisión, 3 otro).');
        }
        if (! $this->plazos->dentroDelPlazo($momento, $this->plazos->limiteInvalidacion($dte))) {
            throw new DteException('FECHA FUERA DE PLAZO PERMITIDO: el plazo para invalidar este documento venció el '
                .$this->plazos->limiteInvalidacion($dte)->format('d/m/Y').'.');
        }
        if ($momento->setTimezone(Plazos::zona())->format('Y-m-d') < $dte->fecha_emision->format('Y-m-d')) {
            throw new DteException('La fecha del evento no puede ser anterior a la fecha de generación del documento.');
        }

        if ($datos->tipoAnulacion === 2) {
            if ($datos->codigoGeneracionR !== null) {
                throw new DteException('Al rescindir la operación no se indica documento que reemplaza.');
            }
        } else {
            if ($datos->codigoGeneracionR === null && ! in_array($dte->tipo, self::SIN_REEMPLAZO_OBLIGATORIO, true)) {
                throw new DteException('Primero emita el documento que reemplaza y luego invalide este (Normativa 13.1.1).');
            }
            if ($datos->codigoGeneracionR !== null) {
                $reemplazo = Dte::query()->where('codigo_generacion', $datos->codigoGeneracionR)->first();
                if ($reemplazo === null || $reemplazo->sello_recibido === null) {
                    throw new DteException('El documento que reemplaza debe existir y tener sello de recepción.');
                }
                if ($reemplazo->codigo_generacion === $dte->codigo_generacion) {
                    throw new DteException('Un documento no puede reemplazarse a sí mismo.');
                }
            }
        }
        if ($datos->tipoAnulacion === 3 && trim((string) $datos->motivoAnulacion) === '') {
            throw new DteException('Indique el motivo de la invalidación.');
        }
    }
}
