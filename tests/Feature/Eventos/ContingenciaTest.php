<?php

use App\Dte\Eventos\GestorContingencia;
use App\Dte\Excepciones\DteException;
use App\Dte\Hacienda\TransmisorDte;
use App\Dte\Validacion\ValidadorEsquema;
use App\Enums\EstadoDte;
use App\Models\Bitacora;
use App\Models\Contingencia;
use App\Models\Dte;
use App\Models\Evento;
use App\Models\Lote;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
| Terminado cuando (Paso 7): la simulación de 3 horas sin servicio termina con todos los documentos
| en «transmitido». Todo con Http::fake: Hacienda simulada caída y luego disponible.
*/

const MH = 'https://apitest.dtes.mh.gob.sv';
const SELLO_CONT = '202197AF602319654B9C95A5ECA40DAE13AEFB5N';

beforeEach(function () {
    Cache::flush();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-11 08:00:00', 'America/El_Salvador'));
    $this->d = crearEmpresaDePrueba();
    tenancy()->establecer($this->d['empresa']->id);
    $this->credencial = $this->d['credencial']->fresh();
    $this->empresa = $this->d['empresa']->fresh();
    $this->mhDisponible = false;
    $this->llamadas = ['recepcion' => 0, 'auth' => 0, 'contingencia' => 0, 'lote' => 0, 'consultaLote' => 0];
    $this->rechazarEnLote = [];

    $caida = fn () => throw new ConnectionException('cURL error 28: timeout');
    Http::fake([
        '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'eyJhbGciOiJSUzUxMiJ9.Y29udGluZ2VuY2lh.ZmlybWE']),
        MH.'/seguridad/auth' => function () use ($caida) {
            $this->llamadas['auth']++;

            return $this->mhDisponible ? Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer t']]) : $caida();
        },
        MH.'/fesv/recepciondte' => function () use ($caida) {
            $this->llamadas['recepcion']++;

            return $caida();
        },
        MH.'/fesv/recepcion/consultadte' => $caida,
        MH.'/fesv/contingencia' => function () use ($caida) {
            $this->llamadas['contingencia']++;

            return $this->mhDisponible
                ? Http::response(['estado' => 'RECIBIDO', 'fechaHora' => '11/11/2026 11:00:00', 'mensaje' => 'Documento recibido', 'selloRecibido' => SELLO_CONT, 'observaciones' => []])
                : $caida();
        },
        MH.'/fesv/recepcionlote' => function (Request $r) use ($caida) {
            $this->llamadas['lote']++;
            if (! $this->mhDisponible) {
                return $caida();
            }
            $this->lotes[] = $r['documentos'];

            return Http::response(['version' => 1, 'ambiente' => '00', 'estado' => 'RECIBIDO', 'idEnvio' => $r['idEnvio'], 'codigoLote' => sprintf('C86DD410-8BD7-48D3-8908-%012d', count($this->lotes)), 'codigoMsg' => '001', 'descripcionMsg' => 'LOTE RECIBIDO']);
        },
        MH.'/fesv/recepcion/consultadtelote/*' => function () {
            $this->llamadas['consultaLote']++;
            $procesados = $rechazados = [];
            foreach (Dte::query()->where('estado', EstadoDte::Enviado->value)->get() as $i => $dte) {
                $r = ['version' => 2, 'ambiente' => '00', 'codigoGeneracion' => $dte->codigo_generacion, 'fhProcesamiento' => '11/11/2026 11:20:00', 'observaciones' => [], 'index' => $i];
                if (in_array($dte->id, $this->rechazarEnLote, true)) {
                    $rechazados[] = $r + ['estado' => 'RECHAZADO', 'selloRecibido' => null, 'clasificaMsg' => '20', 'codigoMsg' => '802', 'descripcionMsg' => 'No valido'];
                } else {
                    $procesados[] = $r + ['estado' => 'PROCESADO', 'selloRecibido' => strtoupper(substr(sha1($dte->codigo_generacion), 0, 40)), 'clasificaMsg' => '10', 'codigoMsg' => '001', 'descripcionMsg' => 'RECIBIDO Y PROCESADO'];
                }
            }

            return Http::response(['procesados' => $procesados, 'rechazados' => $rechazados]);
        },
    ]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function transmitirConMhCaido(object $t, ?string $tipo = null): Dte
{
    $dte = $tipo === null ? crearDteDePrueba($t->d) : crearDteDeTipo($t->d, $tipo);

    return TransmisorDte::crear()->procesar($dte, $t->empresa, $t->credencial);
}

function avanzarMinutos(int $minutos): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes($minutos));
}

it('sin respuesta tras los reintentos: abre la contingencia y re-genera el documento en modo contingencia', function () {
    $dte = transmitirConMhCaido($this);
    $c = Contingencia::query()->firstOrFail();
    $ident = $dte->json_original['identificacion'];

    expect($dte->estado)->toBe(EstadoDte::Transitorio)
        ->and($dte->contingencia_id)->toBe($c->id)
        ->and($c->estado)->toBe(Contingencia::ACTIVA)
        ->and([$ident['tipoModelo'], $ident['tipoOperacion'], $ident['tipoContingencia']])->toBe([2, 2, 1])
        ->and($dte->jws)->toBe('eyJhbGciOiJSUzUxMiJ9.Y29udGluZ2VuY2lh.ZmlybWE')
        ->and((new ValidadorEsquema(config('dte.esquemas')))->validar($dte->json_texto, '01'))->toBe([])
        // Envío + 2 reenvíos sin respuesta (Hacienda caída falla ya al iniciar sesión).
        ->and($dte->eventosInternos()->where('motivo', 'like', 'Sin respuesta de Hacienda%')->count())->toBe(3)
        ->and(Bitacora::query()->where('accion', 'contingencia.iniciada')->count())->toBe(1);
});

it('con una contingencia activa, los documentos nuevos entran directo sin insistir con Hacienda', function () {
    transmitirConMhCaido($this);
    $antes = $this->llamadas['recepcion'];

    $segundo = transmitirConMhCaido($this);

    expect($segundo->estado)->toBe(EstadoDte::Transitorio)
        ->and($this->llamadas['recepcion'])->toBe($antes)
        ->and(Contingencia::query()->count())->toBe(1)
        ->and(Contingencia::query()->first()->dtes()->count())->toBe(2);
});

it('simulación: 3 horas sin servicio y luego todos los documentos quedan transmitidos', function () {
    $dtes = collect([transmitirConMhCaido($this)]);
    // Durante 3 horas se siguen emitiendo documentos y el scheduler corre cada 15 minutos.
    for ($tick = 1; $tick <= 12; $tick++) {
        avanzarMinutos(15);
        if ($tick % 3 === 0) {
            $dtes->push(transmitirConMhCaido($this, $tick === 6 ? '03' : null));
        }
        Artisan::call('dte:contingencia:procesar');
        tenancy()->establecer($this->d['empresa']->id);
    }

    $c = Contingencia::query()->firstOrFail();
    expect($c->estado)->toBe(Contingencia::ACTIVA)
        ->and($c->ultimo_intento_at)->not->toBeNull()
        ->and(Dte::query()->where('estado', EstadoDte::Transitorio->value)->count())->toBe(5)
        ->and($this->llamadas['contingencia'])->toBe(0);

    // Vuelve el servicio.
    $this->mhDisponible = true;
    avanzarMinutos(15);
    Artisan::call('dte:contingencia:procesar');
    tenancy()->establecer($this->d['empresa']->id);

    $c->refresh();
    $evento = Evento::query()->findOrFail($c->evento_id);
    expect($c->fin)->not->toBeNull()
        ->and($evento->sello_recibido)->toBe(SELLO_CONT)
        ->and((new ValidadorEsquema(config('dte.esquemas')))->validar($evento->json_texto, 'contingencia'))->toBe([])
        ->and($evento->json_original['detalleDTE'])->toHaveCount(5)
        ->and($evento->json_original['motivo']['fInicio'])->toBe('2026-11-11')
        ->and(Dte::query()->where('estado', EstadoDte::Transmitido->value)->count())->toBe(5)
        ->and(Dte::query()->whereNull('sello_recibido')->count())->toBe(0)
        ->and($c->estado)->toBe(Contingencia::COMPLETADA)
        ->and(Lote::query()->value('estado'))->toBe('procesado');

    // El evento se envió ANTES que el lote, y el lote lleva las firmas en modo contingencia.
    expect($this->llamadas['contingencia'])->toBe(1)->and($this->llamadas['lote'])->toBe(1)
        ->and($this->lotes[0])->toHaveCount(5);
});

it('envía los documentos en lotes del tamaño máximo configurado', function () {
    config(['dte.lote.max_documentos' => 2]);
    for ($i = 0; $i < 5; $i++) {
        transmitirConMhCaido($this);
    }
    $this->mhDisponible = true;

    app(GestorContingencia::class)->procesar($this->empresa, $this->credencial);

    expect(Lote::query()->count())->toBe(3)
        ->and(collect($this->lotes)->map(fn ($l) => count($l))->all())->toBe([2, 2, 1])
        ->and(Dte::query()->where('estado', EstadoDte::Transmitido->value)->count())->toBe(5);
});

it('los rechazados del lote quedan rechazados y el resto transmitido', function () {
    $a = transmitirConMhCaido($this);
    $b = transmitirConMhCaido($this);
    $this->rechazarEnLote = [$b->id];
    $this->mhDisponible = true;

    app(GestorContingencia::class)->procesar($this->empresa, $this->credencial);

    expect($a->fresh()->estado)->toBe(EstadoDte::Transmitido)
        ->and($b->fresh()->estado)->toBe(EstadoDte::Rechazado)
        ->and(Contingencia::query()->value('estado'))->toBe(Contingencia::COMPLETADA);
});

it('más de 3 días de contingencia: alerta y genera el borrador del Informe Técnico', function () {
    transmitirConMhCaido($this);
    avanzarMinutos(3 * 24 * 60 + 15);

    app(GestorContingencia::class)->procesar($this->empresa, $this->credencial);
    $c = Contingencia::query()->firstOrFail();

    expect($c->alerta_informe_at)->not->toBeNull()
        ->and($c->informe_borrador)->toContain('INFORME TÉCNICO DE CONTINGENCIA')->toContain('Documentos emitidos en contingencia: 1')
        ->and(Bitacora::query()->where('accion', 'contingencia.informe_tecnico_requerido')->count())->toBe(1);

    // La alerta no se repite en cada ciclo.
    app(GestorContingencia::class)->procesar($this->empresa, $this->credencial);
    expect(Bitacora::query()->where('accion', 'contingencia.informe_tecnico_requerido')->count())->toBe(1);
});

it('los tipos que no admiten contingencia vuelven a «firmado» para reintentar', function () {
    $dte = transmitirConMhCaido($this, '08');

    expect($dte->estado)->toBe(EstadoDte::Firmado)
        ->and($dte->contingencia_id)->toBeNull()
        ->and(Contingencia::query()->count())->toBe(0);
});

it('sin datos del responsable de la empresa no se puede enviar el evento (y se avisa)', function () {
    transmitirConMhCaido($this);
    tenancy()->sinAislamiento(fn () => $this->empresa->forceFill(['responsable_nombre' => null])->save());
    $this->mhDisponible = true;

    expect(fn () => app(GestorContingencia::class)->procesar($this->empresa->fresh(), $this->credencial))
        ->toThrow(DteException::class, 'responsable');
});
