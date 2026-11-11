<?php

use App\Dte\Credenciales\AlmacenCertificados;
use App\Models\EmpresaCredencial;
use App\Support\Seguridad\Secretos;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/*
| Terminado cuando (Paso 1): las credenciales no aparecen en toArray, toJson,
| los logs ni las excepciones. Van cifradas con DTE_SECRETS_KEY (no APP_KEY).
*/

const PWD_API = 'ClaveApiFalsa#2026';
const PWD_PRIVADA = 'LlavePrivadaFalsa#2026';

beforeEach(function () {
    $this->datos = crearEmpresaDePrueba();
    tenancy()->establecer($this->datos['empresa']->id);
    $this->credencial = EmpresaCredencial::query()->firstOrFail();
});

function contieneSecreto(string $texto): bool
{
    return str_contains($texto, PWD_API) || str_contains($texto, PWD_PRIVADA);
}

it('descifra los secretos solo cuando el código los pide', function () {
    expect($this->credencial->pwd_api)->toBe(PWD_API)
        ->and($this->credencial->pwd_privada)->toBe(PWD_PRIVADA);
});

it('no expone los secretos en toArray, toJson, json_encode, print_r ni var_dump', function () {
    $c = $this->credencial;
    $c->pwd_api; // forzar una lectura previa no debe cambiar nada

    expect($c->toArray())->not->toHaveKeys(['pwd_api', 'pwd_privada'])
        ->and(contieneSecreto($c->toJson()))->toBeFalse()
        ->and(contieneSecreto((string) json_encode($c)))->toBeFalse()
        ->and(contieneSecreto(print_r($c, true)))->toBeFalse()
        ->and(contieneSecreto(var_export($c->getAttributes(), true)))->toBeFalse();

    ob_start();
    var_dump($c);
    expect(contieneSecreto((string) ob_get_clean()))->toBeFalse()
        ->and(contieneSecreto((string) json_encode($c->resumenSeguro())))->toBeFalse();
});

it('guarda los secretos cifrados con DTE_SECRETS_KEY y no con APP_KEY', function () {
    $fila = DB::selectOne('SELECT pwd_api, pwd_privada FROM empresa_credenciales WHERE id = ?', [$this->credencial->id]);

    expect(contieneSecreto($fila->pwd_api.$fila->pwd_privada))->toBeFalse()
        ->and(app(Secretos::class)->descifrar($fila->pwd_api))->toBe(PWD_API)
        ->and(fn () => Crypt::decryptString($fila->pwd_api))->toThrow(DecryptException::class);
});

it('se niega a usar DTE_SECRETS_KEY si es igual a APP_KEY o si falta', function () {
    config(['dte.secrets_key' => config('app.key')]);
    expect(fn () => (new Secretos)->cifrar('x'))->toThrow(RuntimeException::class, 'distinta de APP_KEY');

    config(['dte.secrets_key' => null]);
    expect(fn () => (new Secretos)->cifrar('x'))->toThrow(RuntimeException::class, 'Falta DTE_SECRETS_KEY');
});

it('no escribe los secretos en los logs aunque se registre el modelo', function () {
    $archivo = storage_path('logs/prueba-secretos.log');
    @unlink($archivo);
    config(['logging.channels.prueba' => ['driver' => 'single', 'path' => $archivo, 'level' => 'debug']]);

    Log::channel('prueba')->info('Credencial cargada', ['credencial' => $this->credencial, 'datos' => $this->credencial->toArray()]);

    $contenido = (string) file_get_contents($archivo);
    @unlink($archivo);
    expect($contenido)->toContain('Credencial cargada')
        ->and(contieneSecreto($contenido))->toBeFalse();
});

it('no filtra los secretos en las excepciones (SensitiveParameter)', function () {
    try {
        $this->credencial->establecerSecretos(PWD_API, '   ');
        $this->fail('Debió lanzar una excepción');
    } catch (InvalidArgumentException $e) {
        expect(contieneSecreto((string) $e))->toBeFalse()
            ->and(contieneSecreto(print_r($e->getTrace(), true)))->toBeFalse();

        $marco = collect($e->getTrace())->firstWhere('function', 'establecerSecretos');
        foreach ($marco['args'] ?? [] as $argumento) {
            expect($argumento)->toBeInstanceOf(SensitiveParameterValue::class);
        }
    }
});

it('reinicia el contador de logins fallidos al cargar secretos nuevos', function () {
    $c = $this->credencial;
    $c->logins_fallidos = 3;
    $c->bloqueado_at = now();
    $c->establecerSecretos('OtraClaveFalsa#1', 'OtraLlaveFalsa#1')->save();

    expect($c->fresh()->logins_fallidos)->toBe(0)->and($c->fresh()->bloqueado_at)->toBeNull();
});

it('guarda el certificado como {NIT}.crt con permisos 600 en el disco privado', function () {
    Storage::disk('certificados')->deleteDirectory('/');
    $empresa = $this->datos['empresa'];
    $contenido = '<CertificadoMH>certificado de prueba</CertificadoMH>';

    app(AlmacenCertificados::class)->guardar($empresa, $this->credencial, $contenido);

    $ruta = Storage::disk('certificados')->path($empresa->nit.'.crt');
    clearstatcache(true, $ruta);
    expect($ruta)->toBeFile()
        ->and(substr(sprintf('%o', fileperms($ruta)), -3))->toBe('600')
        ->and($this->credencial->fresh()->certificado_sha256)->toBe(hash('sha256', $contenido))
        ->and(config('filesystems.disks.certificados.serve'))->toBeFalse()
        ->and(app(AlmacenCertificados::class)->existe($empresa))->toBeTrue();

    Storage::disk('certificados')->delete($empresa->nit.'.crt');
});
