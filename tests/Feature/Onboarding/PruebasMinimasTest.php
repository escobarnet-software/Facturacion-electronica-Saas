<?php

use App\Dte\Pruebas\EjecutorPruebasMinimas;
use App\Dte\Support\CodigoGeneracion;
use App\Enums\EstadoDte;
use App\Enums\Rol;
use App\Jobs\EjecutarPruebasMinimas;
use App\Livewire\Panel\Onboarding;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\EmpresaCredencial;
use App\Models\Evento;
use App\Models\PruebaMinima;
use App\Models\Usuario;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Terminado cuando (Paso 10): --dry-run genera documentos válidos en las cantidades exactas, y la
| ejecución (aquí con Hacienda simulada: Http::fake) completa al menos FE y CCF.
*/

const MH_PM = 'https://apitest.dtes.mh.gob.sv';

beforeEach(function () {
    Cache::flush();
    $this->d = crearEmpresaDePrueba(Rol::Dueno);
    tenancy()->establecer($this->d['empresa']->id);
    $this->empresa = $this->d['empresa']->fresh();
    $this->rechazarDesde = null;   // número de envío a partir del cual Hacienda rechaza
    $this->envios = 0;

    Http::fake([
        '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'eyJhbGciOiJSUzUxMiJ9.cHJ1ZWJh.ZmlybWE']),
        MH_PM.'/seguridad/auth' => Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer t']]),
        MH_PM.'/fesv/recepciondte' => function (Request $r) {
            $this->envios++;
            if ($this->rechazarDesde !== null && $this->envios >= $this->rechazarDesde) {
                return Http::response(['estado' => 'RECHAZADO', 'codigoGeneracion' => $r['codigoGeneracion'], 'selloRecibido' => null, 'codigoMsg' => '020', 'descripcionMsg' => 'CALCULO INCORRECTO'], 400);
            }

            return Http::response(['version' => 2, 'ambiente' => '00', 'estado' => 'PROCESADO', 'codigoGeneracion' => $r['codigoGeneracion'],
                'selloRecibido' => strtoupper(substr(sha1($r['codigoGeneracion'].$this->envios), 0, 40)), 'fhProcesamiento' => now('America/El_Salvador')->format('d/m/Y H:i:s'),
                'codigoMsg' => '001', 'descripcionMsg' => 'RECIBIDO', 'observaciones' => []]);
        },
        MH_PM.'/fesv/anulardte' => Http::response(['estado' => 'PROCESADO', 'selloRecibido' => str_repeat('I', 40), 'codigoMsg' => '001']),
        MH_PM.'/fesv/contingencia' => Http::response(['estado' => 'RECIBIDO', 'selloRecibido' => str_repeat('K', 40), 'mensaje' => 'Documento recibido', 'observaciones' => []]),
        MH_PM.'/fesv/recepcionlote' => fn (Request $r) => Http::response(['estado' => 'RECIBIDO', 'idEnvio' => $r['idEnvio'], 'codigoLote' => CodigoGeneracion::nuevo()]),
        MH_PM.'/fesv/recepcion/consultadtelote/*' => Http::response(['procesados' => [], 'rechazados' => []]),
    ]);
});

it('--dry-run genera documentos válidos de los 11 tipos en las cantidades exactas, sin guardar ni transmitir', function () {
    $resultado = app(EjecutorPruebasMinimas::class)->simular($this->empresa, EjecutorPruebasMinimas::ORDEN_DOCUMENTOS);

    foreach (config('dte.pruebas_minimas.documentos') as $tipo => $requeridas) {
        expect($resultado[(string) $tipo]['generados'])->toBe($requeridas, "Cantidad de {$tipo}")
            ->and($resultado[(string) $tipo]['validos'])->toBe($requeridas, "Válidos de {$tipo}: ".($resultado[(string) $tipo]['errores'][0] ?? ''));
    }
    expect(array_sum(array_column($resultado, 'generados')))->toBe(605)
        ->and(Dte::query()->count())->toBe(0)
        ->and(Correlativo::query()->count())->toBe(0);
    Http::assertNothingSent();

    expect(Artisan::call('dte:pruebas-minimas', ['empresa' => $this->empresa->nit, '--dry-run' => true, '--tipos' => '01,03']))->toBe(0)
        ->and(Artisan::output())->toContain('Simulación')->toContain('90')->toContain('75');
});

it('la ejecución completa las pruebas de Factura (90) y Crédito Fiscal (75) con Hacienda simulada', function () {
    expect(Artisan::call('dte:pruebas-minimas', ['empresa' => $this->empresa->id, '--tipos' => '01,03']))->toBe(0);

    $fe = PruebaMinima::query()->where('tipo', '01')->sole();
    $ccf = PruebaMinima::query()->where('tipo', '03')->sole();
    expect([$fe->exitosas, $fe->requeridas, $fe->estado])->toBe([90, 90, 'completada'])
        ->and([$ccf->exitosas, $ccf->requeridas, $ccf->estado])->toBe([75, 75, 'completada'])
        ->and(Dte::query()->where('estado', EstadoDte::Transmitido->value)->count())->toBe(165)
        ->and(Dte::query()->where('tipo', '03')->max('numero_control'))->toBe('DTE-03-M001P001-000000000000075');
});

it('se detiene tras 5 rechazos seguidos y al reanudar continúa donde quedó', function () {
    config(['dte.pruebas_minimas.documentos.01' => 20]);
    $this->rechazarDesde = 11;

    Artisan::call('dte:pruebas-minimas', ['empresa' => $this->empresa->id, '--tipos' => '01']);
    $fila = PruebaMinima::query()->sole();
    expect([$fila->exitosas, $fila->fallidas, $fila->estado])->toBe([10, 5, 'detenida'])
        ->and($fila->ultimo_error)->toContain('sello');

    $this->rechazarDesde = null;
    expect(Artisan::call('dte:pruebas-minimas', ['empresa' => $this->empresa->id, '--tipos' => '01']))->toBe(0);
    expect($fila->fresh()->exitosas)->toBe(20)
        ->and($fila->fresh()->estado)->toBe('completada')
        ->and(Dte::query()->whereNotNull('sello_recibido')->count())->toBe(20);
});

it('las notas de crédito y débito usan un Crédito Fiscal sellado de las pruebas', function () {
    config(['dte.pruebas_minimas.documentos' => ['03' => 2, '05' => 2, '06' => 2]]);

    expect(Artisan::call('dte:pruebas-minimas', ['empresa' => $this->empresa->id, '--tipos' => '05']))->toBe(1);
    expect(PruebaMinima::query()->where('tipo', '05')->value('ultimo_error'))->toContain('Crédito Fiscal sellado');

    expect(Artisan::call('dte:pruebas-minimas', ['empresa' => $this->empresa->id, '--tipos' => '03,05,06']))->toBe(0);
    $ccf = Dte::query()->where('tipo', '03')->whereNotNull('sello_recibido')->latest('id')->first();
    expect(Dte::query()->where('tipo', '05')->latest('id')->first()->json_original['documentoRelacionado'][0]['numeroDocumento'])->toBe($ccf->codigo_generacion);
});

it('ejecuta las pruebas de los 4 eventos', function () {
    config(['dte.pruebas_minimas.eventos' => ['invalidacion' => 2, 'retorno' => 2, 'operaciones_especiales' => 2, 'contingencia' => 2]]);

    expect(Artisan::call('dte:pruebas-minimas', ['empresa' => $this->empresa->id, '--tipos' => 'invalidacion,retorno,operaciones_especiales,contingencia']))->toBe(0);

    foreach (['invalidacion', 'retorno', 'operaciones_especiales', 'contingencia'] as $tipo) {
        expect(PruebaMinima::query()->where('tipo', $tipo)->value('exitosas'))->toBe(2, $tipo);
        expect(Evento::query()->where('tipo', $tipo)->where('estado', 'procesado')->count())->toBe(2, $tipo);
    }
    expect(Dte::query()->where('estado', EstadoDte::Invalidado->value)->count())->toBe(2)
        ->and(Dte::query()->where('estado', EstadoDte::Ajustado->value)->count())->toBe(2);
});

it('el comando valida tipos, credenciales y ambiente', function () {
    expect(Artisan::call('dte:pruebas-minimas', ['empresa' => $this->empresa->id, '--tipos' => '99']))->toBe(1)
        ->and(Artisan::output())->toContain('Tipos desconocidos');
    expect(Artisan::call('dte:pruebas-minimas', ['empresa' => '00000000000000']))->toBe(1);

    EmpresaCredencial::query()->delete();
    expect(Artisan::call('dte:pruebas-minimas', ['empresa' => $this->empresa->id, '--tipos' => '01']))->toBe(1)
        ->and(Artisan::output())->toContain('credenciales');
});

// ===================== Asistente =====================

it('el asistente guarda los datos de la empresa', function () {
    $this->actingAs($this->d['usuario']);

    Livewire::test(Onboarding::class)
        ->set('empresa.razon_social', 'PUPUSERÍA NUEVA, S.A. DE C.V.')
        ->set('empresa.responsable_num_doc', '098765432')
        ->call('guardarEmpresa')
        ->assertHasNoErrors()
        ->assertSee('Datos de la empresa guardados');

    expect($this->empresa->fresh()->razon_social)->toBe('PUPUSERÍA NUEVA, S.A. DE C.V.')
        ->and($this->empresa->fresh()->responsable_num_doc)->toBe('098765432');
});

it('el asistente carga credenciales cifradas y el certificado, y nunca los muestra de vuelta', function () {
    Storage::fake('certificados');
    $this->actingAs($this->d['usuario']);

    $componente = Livewire::test(Onboarding::class)
        ->set('ambienteCredencial', '01')
        ->set('certificado', UploadedFile::fake()->createWithContent('certificado.crt', '<CertificadoMH>prueba</CertificadoMH>'))
        ->set('pwdApi', 'ClaveApiSegura#2026')
        ->set('pwdPrivada', 'LlavePrivadaSegura#2026')
        ->call('guardarCredenciales')
        ->assertHasNoErrors()
        ->assertSet('pwdApi', '')
        ->assertSet('pwdPrivada', '')
        ->assertDontSee('ClaveApiSegura#2026')
        ->assertDontSee('LlavePrivadaSegura#2026');

    $credencial = EmpresaCredencial::query()->where('ambiente', '01')->sole();
    expect($credencial->pwd_api)->toBe('ClaveApiSegura#2026')
        ->and($credencial->getRawOriginal('pwd_api'))->not->toContain('ClaveApiSegura')
        ->and(Storage::disk('certificados')->get($this->empresa->nit.'.crt'))->toBe('<CertificadoMH>prueba</CertificadoMH>');
});

it('el asistente lanza las pruebas en segundo plano y muestra el avance', function () {
    Queue::fake();
    $this->actingAs($this->d['usuario']);
    PruebaMinima::query()->create(['tipo' => '01', 'requeridas' => 90, 'exitosas' => 45, 'estado' => 'en_curso']);

    Livewire::test(Onboarding::class)->set('tiposPrueba', ['01', '03'])->call('ejecutarPruebas')
        ->assertSet('error', null)->assertSee('45/90');

    Queue::assertPushed(EjecutarPruebasMinimas::class, fn ($job) => $job->tipos === ['01', '03'] && $job->empresaId === $this->empresa->id);
});

it('pasar a producción exige las credenciales de producción', function () {
    $this->actingAs($this->d['usuario']);

    Livewire::test(Onboarding::class)->call('pasarAProduccion')->assertSet('error', fn ($e) => str_contains((string) $e, 'PRODUCCIÓN'));
    expect($this->empresa->fresh()->ambiente)->toBe('00');

    (new EmpresaCredencial(['ambiente' => '01', 'usuario_api' => $this->empresa->nit]))->establecerSecretos('ProdApiFalsa#2026', 'ProdLlaveFalsa#2026')->save();
    Livewire::test(Onboarding::class)->call('pasarAProduccion')->assertSet('error', null);
    expect($this->empresa->fresh()->ambiente)->toBe('01');
});

it('solo el dueño accede a la configuración', function () {
    $contador = Usuario::factory()->create();
    $this->empresa->usuarios()->attach($contador->id, ['rol' => Rol::Contador->value]);
    $this->actingAs($contador);
    tenancy()->fijarUsuario($contador->id);

    $this->get(route('panel.onboarding'))->assertForbidden();
});
