<?php

declare(strict_types=1);

namespace App\Dte\Eventos;

use App\Dte\Excepciones\DteException;
use App\Dte\Firma\FirmadorClient;
use App\Dte\Hacienda\HaciendaClient;
use App\Dte\Hacienda\SinRespuestaException;
use App\Dte\Hacienda\TransmisorDte;
use App\Dte\Support\CodigoGeneracion;
use App\Enums\EstadoDte;
use App\Models\Bitacora;
use App\Models\Contingencia;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\EmpresaCredencial;
use App\Models\Establecimiento;
use App\Models\Evento;
use App\Models\Lote;
use App\Models\PuntoVenta;
use App\Support\DteJson;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Contingencia (Normativa 13.2, regla 9; MTIST 3.2).
 *
 *  1. Si tras la política de reintentos no se pudo transmitir, se abre una contingencia y el
 *     documento se re-genera con tipoModelo 2, tipoOperacion 2, tipoContingencia y motivoContin
 *     (mismo código de generación) y se vuelve a firmar. Queda «transitorio» y se entrega.
 *  2. Cada 15 minutos (scheduler) se prueba el servicio. Cuando vuelve, se cierra la contingencia
 *     y se envía el Evento de Contingencia (≤ 24 h desde el cese) con el detalle de los documentos.
 *  3. Con el sello del evento, se envían los documentos por lotes de 100 (≤ 72 h) y se consultan
 *     los lotes hasta tener el sello de cada uno.
 *  4. Si dura más de 3 días consecutivos, se alerta y se genera el borrador del Informe Técnico.
 */
final class GestorContingencia
{
    /** Documentos que admiten contingencia (Normativa regla 9, filas 2 y 3). */
    public const TIPOS = ['01', '03', '04', '05', '06', '07', '11', '14'];

    public const VERSION_EVENTO = 4;

    /** CAT-005: 1 = no disponibilidad de sistema del MH. */
    public const POR_FALLA_DE_HACIENDA = 1;

    public function __construct(
        private readonly Plazos $plazos = new Plazos,
        private readonly FirmadorClient $firmador = new FirmadorClient,
    ) {}

    public function admite(string $tipoDte): bool
    {
        return in_array($tipoDte, self::TIPOS, true);
    }

    public function activa(string $ambiente): ?Contingencia
    {
        return Contingencia::query()->where('ambiente', $ambiente)->where('estado', Contingencia::ACTIVA)->latest('id')->first();
    }

    /** Mete un DTE (sin sello) en la contingencia activa, abriéndola si hace falta, y lo re-firma. */
    public function registrar(Dte $dte, Empresa $empresa, EmpresaCredencial $credencial, int $tipo = self::POR_FALLA_DE_HACIENDA, ?string $motivo = null): Contingencia
    {
        if (! $this->admite($dte->tipo)) {
            throw new LogicException("El tipo {$dte->tipo} no admite contingencia.");
        }
        if ($dte->sello_recibido !== null) {
            throw new LogicException('Un documento con sello no entra en contingencia.');
        }

        $contingencia = $this->activa($credencial->ambiente) ?? $this->abrir($credencial->ambiente, $tipo, $motivo);

        $texto = $dte->json_texto;
        $normal = '"tipoModelo":1,"tipoOperacion":1,"tipoContingencia":null,"motivoContin":null';
        if (str_contains($texto, $normal)) {
            $texto = str_replace($normal, '"tipoModelo":2,"tipoOperacion":2,"tipoContingencia":'.$contingencia->tipo
                .',"motivoContin":'.json_encode($contingencia->tipo === 5 ? $contingencia->motivo : null, JSON_UNESCAPED_UNICODE), $texto);
        }
        $jws = $this->firmador->firmar($empresa->nit, $credencial->pwd_privada, $texto);

        $dte->forceFill([
            'json_texto' => $texto,
            'json_original' => json_decode($texto, true, flags: JSON_THROW_ON_ERROR),
            'jws' => $jws,
            'modelo' => 2,
            'operacion' => 2,
            'contingencia_id' => $contingencia->id,
        ])->save();

        // Se entrega al receptor en estado transitorio, con el aviso de contingencia (Normativa 13.2).
        TransmisorDte::entregar($dte);

        return $contingencia;
    }

    /** Un paso del scheduler (cada 15 minutos) para la empresa activa. */
    public function procesar(Empresa $empresa, EmpresaCredencial $credencial, ?CarbonImmutable $ahora = null): void
    {
        $ahora ??= CarbonImmutable::now();
        $hacienda = new HaciendaClient($empresa, $credencial);

        foreach (Contingencia::query()->where('ambiente', $credencial->ambiente)->where('estado', '!=', Contingencia::COMPLETADA)->orderBy('id')->get() as $c) {
            try {
                $this->avanzar($c, $empresa, $credencial, $hacienda, $ahora);
            } catch (SinRespuestaException) {
                // Hacienda sigue sin responder: se reintenta en el siguiente ciclo (cada 15 min).
            }
            $this->vigilarPlazos($c, $ahora);
        }
    }

    private function avanzar(Contingencia $c, Empresa $empresa, EmpresaCredencial $credencial, HaciendaClient $hacienda, CarbonImmutable $ahora): void
    {
        if ($c->estado === Contingencia::ACTIVA) {
            $c->forceFill(['ultimo_intento_at' => $ahora])->save();
            $hacienda->olvidarToken();
            $hacienda->token(); // si no responde lanza SinRespuestaException
            $c->forceFill(['fin' => $ahora, 'estado' => Contingencia::CERRADA])->save();
            Bitacora::registrar('contingencia.finalizada', $c, ['inicio' => $c->inicio->toIso8601String(), 'fin' => $ahora->toIso8601String()]);
        }

        if ($c->estado === Contingencia::CERRADA) {
            $this->enviarEvento($c, $empresa, $credencial, $hacienda, $ahora);
        }

        if ($c->estado === Contingencia::EVENTO_SELLADO) {
            $this->enviarLotes($c, $hacienda);
            $this->consultarLotes($c, $hacienda);

            $pendientes = $c->dtes()->whereIn('estado', [EstadoDte::Transitorio->value, EstadoDte::Enviado->value])->count();
            if ($pendientes === 0) {
                $c->forceFill(['estado' => Contingencia::COMPLETADA])->save();
            }
        }
    }

    private function enviarEvento(Contingencia $c, Empresa $empresa, EmpresaCredencial $credencial, HaciendaClient $hacienda, CarbonImmutable $ahora): void
    {
        $evento = $c->evento_id ? Evento::query()->find($c->evento_id) : null;
        if ($evento === null) {
            $evento = (new FirmaEventos($this->firmador))->firmarYGuardar(
                $empresa, $credencial, Evento::CONTINGENCIA, self::VERSION_EVENTO, $this->armarEvento($c, $empresa, $ahora),
            );
            $c->forceFill(['evento_id' => $evento->id])->save();
        }
        if ($evento->estado === 'rechazado') {
            return; // requiere revisión humana (ya se avisó)
        }

        $respuesta = $hacienda->enviarContingencia((string) $evento->jws);
        $sello = $respuesta['selloRecibido'] ?? null;
        if (($respuesta['estado'] ?? null) === 'RECIBIDO' && is_string($sello) && $sello !== '') {
            $evento->forceFill(['estado' => 'procesado', 'sello_recibido' => $sello, 'respuesta_mh' => $respuesta, 'fh_procesamiento' => $ahora])->save();
            $c->forceFill(['estado' => Contingencia::EVENTO_SELLADO])->save();

            return;
        }

        $evento->forceFill(['estado' => 'rechazado', 'respuesta_mh' => $respuesta])->save();
        Bitacora::registrar('contingencia.evento_rechazado', $c, ['mensaje' => $respuesta['mensaje'] ?? null, 'observaciones' => $respuesta['observaciones'] ?? null]);
    }

    public function armarEvento(Contingencia $c, Empresa $empresa, CarbonImmutable $ahora, ?string $codigoGeneracion = null): string
    {
        if ($empresa->getAttribute('responsable_nombre') === null || $empresa->getAttribute('responsable_num_doc') === null) {
            throw new DteException('Para el Evento de Contingencia complete los datos del responsable de la empresa (nombre y documento).');
        }
        $dtes = $c->dtes()->orderBy('id')->get();
        if ($dtes->isEmpty() || $dtes->count() > 1000) {
            throw new DteException('El Evento de Contingencia debe detallar entre 1 y 1,000 documentos.');
        }

        $primero = $dtes->first();
        $establecimiento = Establecimiento::query()->findOrFail($primero->establecimiento_id);
        $puntoVenta = PuntoVenta::query()->findOrFail($primero->punto_venta_id);
        $zona = Plazos::zona();
        $inicio = CarbonImmutable::parse($c->inicio)->setTimezone($zona);
        $fin = CarbonImmutable::parse($c->fin ?? $ahora)->setTimezone($zona);
        $transmision = $ahora->setTimezone($zona);

        return DteJson::encode([
            'identificacion' => [
                'version' => self::VERSION_EVENTO,
                'ambiente' => $c->ambiente,
                'codigoGeneracion' => $codigoGeneracion ?? CodigoGeneracion::nuevo(),
                'fTransmision' => $transmision->format('Y-m-d'),
                'hTransmision' => $transmision->format('H:i:s'),
            ],
            'emisor' => [
                'nit' => $empresa->nit,
                'nombre' => $empresa->razon_social,
                'nombreResponsable' => $empresa->getAttribute('responsable_nombre'),
                'tipoDocResponsable' => (string) ($empresa->getAttribute('responsable_tipo_doc') ?? '13'),
                'numeroDocResponsable' => $empresa->getAttribute('responsable_num_doc'),
                'tipoEstablecimiento' => $establecimiento->tipo,
                'codEstableMH' => $establecimiento->cod_estable_mh,
                'codPuntoVentaMH' => $puntoVenta->cod_punto_venta_mh,
                'telefono' => (string) $empresa->getAttribute('telefono'),
                'correo' => (string) $empresa->getAttribute('correo'),
            ],
            'detalleDTE' => $dtes->values()->map(fn (Dte $d, int $i) => [
                'noItem' => $i + 1, 'tipoDoc' => $d->tipo, 'codigoGeneracion' => $d->codigo_generacion,
            ])->all(),
            'motivo' => [
                'fInicio' => $inicio->format('Y-m-d'),
                'fFin' => $fin->format('Y-m-d'),
                'hInicio' => $inicio->format('H:i:s'),
                'hFin' => $fin->format('H:i:s'),
                'tipoContingencia' => $c->tipo,
                'motivoContingencia' => $c->motivo,
            ],
        ]);
    }

    private function enviarLotes(Contingencia $c, HaciendaClient $hacienda): void
    {
        $sinLote = $c->dtes()->where('estado', EstadoDte::Transitorio->value)->whereNull('lote_id')->orderBy('id')->get();

        foreach ($sinLote->chunk((int) config('dte.lote.max_documentos', 100)) as $grupo) {
            $idEnvio = CodigoGeneracion::nuevo();
            $respuesta = $hacienda->recibirLote($grupo->pluck('jws')->map(fn ($j) => (string) $j)->values()->all(), $idEnvio);
            if (($respuesta['estado'] ?? null) !== 'RECIBIDO' || ! is_string($respuesta['codigoLote'] ?? null)) {
                Bitacora::registrar('contingencia.lote_rechazado', $c, ['respuesta' => $respuesta]);

                continue;
            }

            $lote = Lote::query()->create([
                'contingencia_id' => $c->id, 'id_envio' => $idEnvio, 'codigo_lote' => $respuesta['codigoLote'],
                'estado' => 'recibido', 'dte_ids' => $grupo->pluck('id')->values()->all(), 'respuesta_mh' => $respuesta,
            ]);
            foreach ($grupo as $dte) {
                $dte->transicionar(EstadoDte::Enviado, 'Enviado en el lote '.$lote->codigo_lote, ['lote_id' => $lote->id]);
            }
        }
    }

    private function consultarLotes(Contingencia $c, HaciendaClient $hacienda): void
    {
        $transmisor = TransmisorDte::crear();

        foreach (Lote::query()->where('contingencia_id', $c->id)->where('estado', 'recibido')->get() as $lote) {
            $respuesta = $hacienda->consultarLote((string) $lote->codigo_lote);
            $resultados = [...($respuesta['procesados'] ?? []), ...($respuesta['rechazados'] ?? [])];

            foreach ($resultados as $r) {
                $dte = Dte::query()->whereKey($lote->dte_ids)->where('codigo_generacion', $r['codigoGeneracion'] ?? '')->first();
                if ($dte !== null && $dte->estado === EstadoDte::Enviado) {
                    $transmisor->aplicarRespuesta($dte, $r, 'Resultado del lote '.$lote->codigo_lote);
                }
            }

            $pendientes = Dte::query()->whereKey($lote->dte_ids)->where('estado', EstadoDte::Enviado->value)->count();
            if ($pendientes === 0) {
                $lote->forceFill(['estado' => 'procesado', 'respuesta_mh' => $respuesta])->save();
            }
        }
    }

    private function vigilarPlazos(Contingencia $c, CarbonImmutable $ahora): void
    {
        $limiteInforme = CarbonImmutable::parse($c->inicio)->addDays((int) config('dte.contingencia.informe_dias', 3));
        $fin = $c->fin !== null ? CarbonImmutable::parse($c->fin) : $ahora;

        if ($c->alerta_informe_at === null && $fin->greaterThan($limiteInforme)) {
            $c->forceFill(['alerta_informe_at' => $ahora, 'informe_borrador' => $this->borradorInforme($c, $fin)])->save();
            Bitacora::registrar('contingencia.informe_tecnico_requerido', $c, ['inicio' => $c->inicio->toIso8601String()]);
        }

        if ($c->estado === Contingencia::CERRADA && $c->fin !== null
            && $ahora->greaterThan($this->plazos->limiteEventoContingencia(CarbonImmutable::parse($c->fin)))) {
            Bitacora::registrar('contingencia.evento_fuera_de_plazo', $c, ['fin' => $c->fin->toIso8601String()]);
        }
    }

    private function borradorInforme(Contingencia $c, CarbonImmutable $hasta): string
    {
        $zona = Plazos::zona();
        $inicio = CarbonImmutable::parse($c->inicio)->setTimezone($zona);
        $documentos = $c->dtes()->count();
        $tipos = $c->dtes()->selectRaw('tipo, count(*) AS n')->groupBy('tipo')->pluck('n', 'tipo')->map(fn ($n, $t) => "{$t}: {$n}")->implode(', ');

        return implode("\n", [
            'BORRADOR — INFORME TÉCNICO DE CONTINGENCIA (Normativa DTE v2.0, regla 13.2.1.1)',
            'Inicio de la contingencia: '.$inicio->format('d/m/Y H:i:s'),
            'Fin de la contingencia: '.($c->fin !== null ? CarbonImmutable::parse($c->fin)->setTimezone($zona)->format('d/m/Y H:i:s') : 'en curso al '.$hasta->setTimezone($zona)->format('d/m/Y H:i:s')),
            'Tipo de contingencia (CAT-005): '.$c->tipo,
            'Motivo: '.($c->motivo ?? 'No disponibilidad del sistema de recepción del Ministerio de Hacienda'),
            "Documentos emitidos en contingencia: {$documentos}".($tipos !== '' ? " ({$tipos})" : ''),
            'Acciones realizadas: reintentos automáticos cada 15 minutos; documentos entregados a los receptores en estado transitorio.',
            'Pendiente de completar por la empresa: causa técnica detallada, evidencias y firma del responsable.',
        ]);
    }

    private function abrir(string $ambiente, int $tipo, ?string $motivo): Contingencia
    {
        $c = Contingencia::query()->create([
            'ambiente' => $ambiente, 'inicio' => now(), 'tipo' => $tipo, 'motivo' => $motivo, 'estado' => Contingencia::ACTIVA,
        ]);
        Bitacora::registrar('contingencia.iniciada', $c, ['tipo' => $tipo]);

        return $c;
    }
}
