<?php

use App\Dte\Excepciones\DteException;
use App\Dte\Firma\FirmadorClient;
use App\Dte\Hacienda\CredencialesBloqueadasException;
use App\Dte\Hacienda\HaciendaClient;
use App\Dte\Hacienda\LoginFallidoException;
use App\Dte\Hacienda\SinRespuestaException;
use App\Dte\Hacienda\TransmisorDte;
use App\Dte\Validacion\ValidadorEsquema;
use App\Enums\EstadoDte;
use App\Jobs\TransmitirDte;
use App\Models\Bitacora;
use App\Models\Dte;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
| Terminado cuando (Paso 4), con Http::fake: éxito, rechazo, timeout seguido de consulta,
| timeout seguido de 2 reenvíos y contingencia, token vencido y login fallido 3 veces.
*/

const URL_AUTH = 'https://apitest.dtes.mh.gob.sv/seguridad/auth';
const URL_RECEPCION = 'https://apitest.dtes.mh.gob.sv/fesv/recepciondte';
const URL_CONSULTA = 'https://apitest.dtes.mh.gob.sv/fesv/recepcion/consultadte';
const TOKEN_FALSO = 'Bearer eyJhbGciOiJIUzUxMiJ9.token.falso';
const SELLO = '20265AE21DAEFC7D4F908350E64BA38F95BDVXW3';

beforeEach(function () {
    Cache::flush();
    $this->d = crearEmpresaDePrueba();
    tenancy()->establecer($this->d['empresa']->id);
    $this->credencial = $this->d['credencial']->fresh();
    $this->dte = crearDteDePrueba($this->d, 'firmado');
});

function authOk(): array
{
    return ['status' => 'OK', 'body' => ['user' => '06140000000000', 'token' => TOKEN_FALSO, 'roles' => ['ROLE_USER'], 'tokenType' => 'Bearer']];
}

function procesado(string $codigo, array $observaciones = ['', '']): array
{
    return [
        'version' => 2, 'ambiente' => '00', 'versionApp' => 2, 'estado' => 'PROCESADO', 'codigoGeneracion' => $codigo,
        'selloRecibido' => SELLO, 'fhProcesamiento' => '13/02/2026 09:58:57', 'clasificaMsg' => '10',
        'codigoMsg' => $observaciones === ['', ''] ? '001' : '002',
        'descripcionMsg' => $observaciones === ['', ''] ? 'RECIBIDO' : 'RECIBIDO CON OBSERVACIONES',
        'observaciones' => $observaciones,
    ];
}

function transmitir(object $t): Dte
{
    return (new TransmisorDte(app(FirmadorClient::class), new ValidadorEsquema(config('dte.esquemas'))))
        ->procesar($t->dte, $t->d['empresa'], $t->credencial);
}

function peticionesA(string $url): int
{
    return Http::recorded(fn (Request $r) => $r->url() === $url)->count();
}

it('éxito: guarda el sello, la respuesta completa y queda transmitido', function () {
    Http::fake([URL_AUTH => Http::response(authOk()), URL_RECEPCION => Http::response(procesado($this->dte->codigo_generacion))]);

    $dte = transmitir($this);

    expect($dte->estado)->toBe(EstadoDte::Transmitido)
        ->and($dte->sello_recibido)->toBe(SELLO)
        ->and($dte->fh_procesamiento->format('Y-m-d H:i:s'))->toBe('2026-02-13 09:58:57')
        ->and($dte->respuesta_mh['codigoMsg'])->toBe('001')
        ->and($dte->observaciones_mh)->toBeNull();

    Http::assertSent(fn (Request $r) => $r->url() === URL_RECEPCION
        && $r->header('Authorization') === [TOKEN_FALSO]
        && $r->header('User-Agent') === [config('dte.api.user_agent')]
        && $r['ambiente'] === '00' && $r['idEnvio'] === 1 && $r['version'] === 2 && $r['tipoDte'] === '01'
        && $r['documento'] === $this->dte->jws && $r['codigoGeneracion'] === $this->dte->codigo_generacion);
    Http::assertSent(fn (Request $r) => $r->url() === URL_AUTH && $r['user'] === $this->d['empresa']->nit && $r['pwd'] === 'ClaveApiFalsa#2026');

    expect($dte->eventosInternos()->pluck('a')->all())->toBe(['enviado', 'transmitido']);
});

it('con observaciones: queda observado y guarda las observaciones', function () {
    Http::fake([URL_AUTH => Http::response(authOk()), URL_RECEPCION => Http::response(procesado($this->dte->codigo_generacion, ['identificacion.fecEmi Difiere de la fecha de envío.']))]);

    $dte = transmitir($this);

    expect($dte->estado)->toBe(EstadoDte::Observado)
        ->and($dte->sello_recibido)->toBe(SELLO)
        ->and($dte->observaciones_mh)->toBe(['identificacion.fecEmi Difiere de la fecha de envío.']);
});

it('rechazo: queda rechazado con el motivo en español y sin sello', function () {
    Http::fake([
        URL_AUTH => Http::response(authOk()),
        URL_RECEPCION => Http::response([
            'version' => 2, 'ambiente' => '00', 'versionApp' => 2, 'estado' => 'RECHAZADO', 'codigoGeneracion' => $this->dte->codigo_generacion,
            'selloRecibido' => null, 'fhProcesamiento' => null, 'clasificaMsg' => null, 'codigoMsg' => '020',
            'descripcionMsg' => '[resumen.totalIva] CALCULO INCORRECTO', 'observaciones' => null,
        ], 400),
    ]);

    $dte = transmitir($this);

    expect($dte->estado)->toBe(EstadoDte::Rechazado)
        ->and($dte->sello_recibido)->toBeNull()
        ->and($dte->respuesta_mh['codigoMsg'])->toBe('020')
        ->and($dte->eventosInternos()->get()->last()->motivo)->toContain('Un cálculo no cuadra')
        ->and(peticionesA(URL_RECEPCION))->toBe(1);
});

it('timeout y luego la consulta encuentra el documento: no se reenvía', function () {
    $envios = 0;
    Http::fake([
        URL_AUTH => Http::response(authOk()),
        URL_RECEPCION => function () use (&$envios) {
            $envios++;
            throw new ConnectionException('cURL error 28: Operation timed out after 8000 milliseconds');
        },
        URL_CONSULTA => Http::response(procesado($this->dte->codigo_generacion)),
    ]);

    $dte = transmitir($this);

    expect($dte->estado)->toBe(EstadoDte::Transmitido)
        ->and($dte->sello_recibido)->toBe(SELLO)
        ->and($envios)->toBe(1)
        ->and(peticionesA(URL_CONSULTA))->toBe(1);

    Http::assertSent(fn (Request $r) => $r->url() === URL_CONSULTA && $r['nitEmisor'] === $this->d['empresa']->nit
        && $r['tdte'] === '01' && $r['codigoGeneracion'] === $this->dte->codigo_generacion);
});

it('timeout, la consulta no lo encuentra y el reenvío funciona (mismo código de generación)', function () {
    $intentos = 0;
    $enviados = [];
    Http::fake([
        URL_AUTH => Http::response(authOk()),
        URL_RECEPCION => function (Request $r) use (&$intentos, &$enviados) {
            $enviados[] = [$r['idEnvio'], $r['codigoGeneracion']];
            if (++$intentos === 1) {
                throw new ConnectionException('timeout');
            }

            return Http::response(procesado($this->dte->codigo_generacion));
        },
        URL_CONSULTA => Http::response(['estado' => 'RECHAZADO', 'selloRecibido' => null, 'codigoMsg' => '014'], 400),
    ]);

    $dte = transmitir($this);

    expect($dte->estado)->toBe(EstadoDte::Transmitido)->and($dte->envios)->toBe(2);
    expect($enviados)->toBe([[1, $this->dte->codigo_generacion], [2, $this->dte->codigo_generacion]]);
});

it('timeout en el envío y en 2 reenvíos: pasa a contingencia (transitorio)', function () {
    $envios = $consultas = 0;
    Http::fake([
        URL_AUTH => Http::response(authOk()),
        URL_RECEPCION => function () use (&$envios) {
            $envios++;
            throw new ConnectionException('timeout');
        },
        URL_CONSULTA => function () use (&$consultas) {
            $consultas++;
            throw new ConnectionException('timeout');
        },
    ]);

    $dte = transmitir($this);

    expect($dte->estado)->toBe(EstadoDte::Transitorio)
        ->and($dte->sello_recibido)->toBeNull()
        ->and($envios)->toBe(3)   // envío + 2 reenvíos, nunca más
        ->and($consultas)->toBe(3)
        ->and($dte->eventosInternos()->get()->last()->motivo)->toContain('contingencia');
});

it('reutiliza el token en caché y lo renueva cuando Hacienda dice que venció', function () {
    $logins = 0;
    $recepciones = 0;
    Http::fake([
        URL_AUTH => function () use (&$logins) {
            $logins++;

            return Http::response(authOk());
        },
        URL_RECEPCION => function () use (&$recepciones) {
            // La segunda petición encuentra el token vencido.
            return ++$recepciones === 2
                ? Http::response(['status' => 'ERROR', 'error' => 'Unauthorized', 'message' => 'ERROR TOKEN INVÁLIDO'], 401)
                : Http::response(procesado('X'));
        },
    ]);

    $hacienda = new HaciendaClient($this->d['empresa'], $this->credencial);
    $hacienda->recibirDte('jws', '01', 2, 'A', 1);
    expect($logins)->toBe(1)
        ->and(Cache::get("dte:token:{$this->d['empresa']->id}:00"))->toBe(TOKEN_FALSO);

    $respuesta = $hacienda->recibirDte('jws', '01', 2, 'A', 2);
    expect($respuesta['estado'])->toBe('PROCESADO')
        ->and($logins)->toBe(2)
        ->and($recepciones)->toBe(3);
});

it('login fallido 3 veces: bloquea, avisa y no vuelve a intentar', function () {
    Http::fake([URL_AUTH => Http::response(['status' => 'ERROR', 'error' => 'Unauthorized', 'message' => 'Usuario no valido'], 401)]);
    $hacienda = fn () => new HaciendaClient($this->d['empresa'], $this->credencial);

    expect(fn () => $hacienda()->token())->toThrow(LoginFallidoException::class)
        ->and(fn () => $hacienda()->token())->toThrow(LoginFallidoException::class)
        ->and(fn () => $hacienda()->token())->toThrow(CredencialesBloqueadasException::class);

    expect($this->credencial->fresh()->logins_fallidos)->toBe(3)
        ->and($this->credencial->fresh()->bloqueado_at)->not->toBeNull()
        ->and($this->d['empresa']->fresh()->estado)->toBe('credenciales_invalidas')
        ->and(Bitacora::query()->where('accion', 'hacienda.credenciales_bloqueadas')->count())->toBe(1);

    // Un cuarto intento NO llega a Hacienda.
    expect(fn () => (new HaciendaClient($this->d['empresa'], $this->credencial->fresh()))->token())
        ->toThrow(CredencialesBloqueadasException::class);
    expect(peticionesA(URL_AUTH))->toBe(3);
});

it('un error de red al iniciar sesión no cuenta como credencial fallida', function () {
    Http::fake([URL_AUTH => fn () => throw new ConnectionException('timeout')]);

    expect(fn () => (new HaciendaClient($this->d['empresa'], $this->credencial))->token())->toThrow(SinRespuestaException::class)
        ->and($this->credencial->fresh()->logins_fallidos)->toBe(0);
});

it('nunca envía un DTE de pruebas con credenciales de producción', function () {
    $this->credencial->ambiente = '01';

    expect(fn () => transmitir($this))->toThrow(LogicException::class);
    Http::assertNothingSent();
});

it('un DTE con sello no se puede modificar ni borrar (trigger)', function () {
    Http::fake([URL_AUTH => Http::response(authOk()), URL_RECEPCION => Http::response(procesado($this->dte->codigo_generacion))]);
    $dte = transmitir($this);

    fallaEnBaseDeDatos(fn () => DB::update("UPDATE dtes SET jws = 'otra' WHERE id = ?", [$dte->id]));
    fallaEnBaseDeDatos(fn () => DB::update("UPDATE dtes SET json_texto = '{}' WHERE id = ?", [$dte->id]));
    fallaEnBaseDeDatos(fn () => DB::delete('DELETE FROM dtes WHERE id = ?', [$dte->id]));

    // Lo demás (por ejemplo, la ruta del PDF) sí puede actualizarse.
    expect(DB::update("UPDATE dtes SET pdf_path = 'x.pdf' WHERE id = ?", [$dte->id]))->toBe(1);
});

it('firma con el firmador oficial: valida el esquema, envía el JSON exacto y no filtra la clave', function () {
    $dte = crearDteDePrueba($this->d);
    Http::fake([
        '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'eyJhbGciOiJSUzUxMiJ9.cGF5bG9hZA.ZmlybWE']),
        URL_AUTH => Http::response(authOk()),
        URL_RECEPCION => Http::response(procesado($dte->codigo_generacion)),
    ]);
    $this->dte = $dte;

    $resultado = transmitir($this);

    expect($resultado->estado)->toBe(EstadoDte::Transmitido)
        ->and($resultado->jws)->toBe('eyJhbGciOiJSUzUxMiJ9.cGF5bG9hZA.ZmlybWE')
        ->and($resultado->eventosInternos()->pluck('a')->all())->toBe(['validado', 'firmado', 'enviado', 'transmitido']);

    Http::assertSent(function (Request $r) use ($dte) {
        if (! str_contains($r->url(), 'firmardocumento')) {
            return false;
        }
        $cuerpo = $r->body();

        return str_contains($cuerpo, '"dteJson":'.$dte->json_texto.'}')
            && $r['nit'] === $this->d['empresa']->nit && $r['activo'] === true && $r['passwordPri'] === 'LlavePrivadaFalsa#2026';
    });
});

it('si el firmador falla, el error es claro y no contiene la clave privada', function () {
    $dte = crearDteDePrueba($this->d);
    Http::fake(['*firmardocumento*' => Http::response(['status' => 'ERROR', 'body' => ['codigo' => '803', 'mensaje' => 'No existe llave publica para este nit']])]);
    $this->dte = $dte;

    try {
        transmitir($this);
        $this->fail('Debió fallar');
    } catch (DteException $e) {
        expect($e->getMessage())->toContain('certificado')
            ->and((string) $e)->not->toContain('LlavePrivadaFalsa#2026');
    }
    expect($dte->fresh()->estado)->toBe(EstadoDte::Validado);
});

it('no firma un documento que no cumple el esquema oficial', function () {
    $dte = crearDteDePrueba($this->d);
    $dte->forceFill(['json_texto' => str_replace('"tipoMoneda":"USD"', '"tipoMoneda":"EUR"', $dte->json_texto)])->save();
    Http::fake();
    $this->dte = $dte;

    expect(fn () => transmitir($this))->toThrow(DteException::class, 'esquema oficial');
    Http::assertNothingSent();
});

it('el job fija la empresa recibida, transmite en la cola «transmision» y limpia la empresa', function () {
    Http::fake([URL_AUTH => Http::response(authOk()), URL_RECEPCION => Http::response(procesado($this->dte->codigo_generacion))]);
    $job = new TransmitirDte($this->d['empresa']->id, $this->dte->id);
    tenancy()->limpiar();

    expect($job->queue)->toBe('transmision');
    dispatch_sync($job);

    expect(tenancy()->empresaId())->toBeNull();
    tenancy()->establecer($this->d['empresa']->id);
    expect($this->dte->fresh()->estado)->toBe(EstadoDte::Transmitido);
});
