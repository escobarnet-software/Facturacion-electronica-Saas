<?php

use App\Enums\Rol;
use App\Livewire\Admin\Empresas;
use App\Mail\AlertaPlataforma;
use App\Mail\InvitacionDueno;
use App\Models\Contingencia;
use App\Models\Empresa;
use App\Models\EmpresaCredencial;
use App\Models\HaciendaMetrica;
use App\Models\Plan;
use App\Models\Producto;
use App\Models\Usuario;
use App\Models\Webhook;
use App\Plataforma\Salud\Vigilante;
use App\Support\Seguridad\Secretos;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
| Paso 14 (parte independiente del hosting): cabeceras de seguridad, límites de peticiones, rotación de
| llaves, healthcheck, alertas e invitación del dueño. Sin conexión a Hacienda.
*/

it('envía las cabeceras de seguridad y una CSP con nonce en el panel, el login y la API', function () {
    $r = $this->get('/login')->assertOk();
    expect($r->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($r->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($r->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($r->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'")->toContain("object-src 'none'");
    preg_match("/'nonce-([^']+)'/", (string) $r->headers->get('Content-Security-Policy'), $m);
    expect($m[1] ?? '')->toHaveLength(32);

    $this->getJson('/api/v1/openapi.json')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($this->get('/login', ['X-Forwarded-Proto' => 'http'])->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('limita los intentos: login y API devuelven 429 al abusar', function () {
    foreach (range(1, 5) as $_) {
        $this->post('/login', ['email' => 'nadie@prueba.test', 'password' => 'incorrecta']);
    }
    $this->post('/login', ['email' => 'nadie@prueba.test', 'password' => 'incorrecta'])->assertStatus(429);

    foreach (range(1, 30) as $_) {
        $this->getJson('/salud');
    }
    $this->getJson('/salud')->assertStatus(429);
});

it('rota DTE_SECRETS_KEY sin perder credenciales: descifra con la anterior y vuelve a cifrar con la nueva', function () {
    $d = crearEmpresaDePrueba();
    tenancy()->establecer($d['empresa']->id);
    Webhook::query()->create(['url' => 'https://hooks.cliente.test/x', 'secreto' => 'whsec_rotacion', 'eventos' => ['dte.transmitido']]);
    $anterior = config('dte.secrets_key');
    $nueva = 'base64:'.base64_encode(random_bytes(32));
    $secretos = app(Secretos::class);

    config(['dte.secrets_key' => $nueva, 'dte.secrets_previous_keys' => $anterior]);
    $secretos->reiniciar();
    expect(EmpresaCredencial::query()->sole()->pwd_api)->toBe('ClaveApiFalsa#2026');   // sigue legible
    $this->artisan('dte:secretos:rotar --verificar')->assertFailed();
    tenancy()->limpiar();
    $this->artisan('dte:secretos:rotar')->expectsOutputToContain('3 valor(es)')->assertSuccessful();
    $this->artisan('dte:secretos:rotar --verificar')->assertSuccessful();

    // Ya sin la llave anterior, todo se lee con la nueva.
    config(['dte.secrets_previous_keys' => '']);
    $secretos->reiniciar();
    tenancy()->establecer($d['empresa']->id);
    $credencial = EmpresaCredencial::query()->sole();
    expect($credencial->pwd_api)->toBe('ClaveApiFalsa#2026')
        ->and($credencial->pwd_privada)->toBe('LlavePrivadaFalsa#2026')
        ->and(Webhook::query()->sole()->secreto)->toBe('whsec_rotacion');

    config(['dte.secrets_key' => config('app.key')]);
    $secretos->reiniciar();
    expect(fn () => $secretos->cifrar('x'))->toThrow(RuntimeException::class, 'distinta de APP_KEY');
    config(['dte.secrets_key' => $anterior]);
    $secretos->reiniciar();
});

it('el healthcheck informa cada componente sin datos de clientes', function () {
    $r = $this->getJson('/salud')->assertOk();
    expect($r->json('componentes.base_de_datos.ok'))->toBeTrue()
        ->and($r->json('componentes.redis.ok'))->toBeTrue()
        ->and(array_keys($r->json('componentes')))->toBe(['base_de_datos', 'redis', 'firmador', 'cola_transmision', 'hacienda']);
});

it('el sondeo de Hacienda está apagado por defecto y, al activarlo, solo hace una petición liviana (simulada)', function () {
    Http::fake(['apitest.dtes.mh.gob.sv/*' => Http::response('', 404)]);
    crearEmpresaDePrueba();

    $this->artisan('dte:hacienda:sondear')->expectsOutputToContain('desactivado')->assertSuccessful();
    Http::assertNothingSent();

    config(['dte.salud.sondeo_hacienda' => true]);
    $this->artisan('dte:hacienda:sondear')->expectsOutputToContain('00: HTTP 404')->assertSuccessful();
    Http::assertSentCount(1);
    expect(HaciendaMetrica::query()->sole()->endpoint)->toBe('/');
});

it('avisa a los superadmin cuando Hacienda falla o hay contingencias, sin repetir la alerta', function () {
    Mail::fake();
    Cache::flush();
    Usuario::factory()->create(['es_superadmin' => true]);
    foreach (range(1, 4) as $_) {
        HaciendaMetrica::query()->create(['ambiente' => '00', 'endpoint' => '/fesv/recepciondte', 'status' => null]);
    }
    $d = crearEmpresaDePrueba();
    tenancy()->ejecutarComo($d['empresa']->id, fn () => Contingencia::query()->create(['ambiente' => '00', 'inicio' => now(), 'tipo' => 1, 'estado' => Contingencia::ACTIVA]));

    $enviadas = app(Vigilante::class)->vigilar();
    expect($enviadas)->toContain('hacienda')->toContain('contingencias');
    Mail::assertQueued(AlertaPlataforma::class, fn (AlertaPlataforma $m) => str_contains($m->mensaje, 'Hacienda está fallando'));

    expect(app(Vigilante::class)->vigilar())->not->toContain('hacienda'); // silencio de 30 min
});

it('el dueño nuevo recibe una invitación de un solo uso y crea su contraseña', function () {
    Mail::fake();
    $plan = Plan::query()->create(['codigo' => 'basico', 'nombre' => 'Básico', 'precio_mensual' => '25.00']);
    $admin = Usuario::factory()->create(['es_superadmin' => true, 'two_factor_confirmed_at' => now()]);
    $this->actingAs($admin);

    Livewire::test(Empresas::class)
        ->set('nueva', ['nit' => '06142803901121', 'nrc' => '2345678', 'razon_social' => 'NUEVA, S.A. DE C.V.', 'correo' => 'info@nueva.test', 'plan_id' => (string) $plan->id, 'dueno_email' => 'duena@nueva.test', 'dueno_nombre' => 'Dueña Nueva'])
        ->call('crear')->assertHasNoErrors()->assertSet('invitacionEnviada', 'duena@nueva.test');

    $enlace = null;
    Mail::assertQueued(InvitacionDueno::class, function (InvitacionDueno $m) use (&$enlace) {
        $enlace = $m->enlace;

        return $m->hasTo('duena@nueva.test');
    });
    auth()->logout();
    $duena = Usuario::query()->where('email', 'duena@nueva.test')->sole();

    $this->get($enlace)->assertOk()->assertSee('Activar su cuenta');
    $this->get(URL::route('invitacion', ['usuario' => $duena->id]))->assertForbidden(); // sin firma
    $this->post($enlace, ['password' => 'corta', 'password_confirmation' => 'corta'])->assertSessionHasErrors('password');
    $this->post($enlace, ['password' => 'ClaveSegura2026x', 'password_confirmation' => 'ClaveSegura2026x'])->assertRedirect(route('seguridad.2fa'));
    $this->assertAuthenticatedAs($duena);

    auth()->logout();
    $this->get($enlace)->assertStatus(410);   // un solo uso
});

it('la revisión de producción falla con la configuración de desarrollo', function () {
    $this->artisan('plataforma:revisar-produccion')->expectsOutputToContain('[FALLA] APP_ENV es production')->assertFailed();
});

it('Horizon solo lo ve el superadmin con doble factor', function () {
    $d = crearEmpresaDePrueba(Rol::Dueno);
    expect(Gate::forUser($d['usuario'])->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser(Usuario::factory()->create(['es_superadmin' => true]))->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser(Usuario::factory()->create(['es_superadmin' => true, 'two_factor_confirmed_at' => now()]))->allows('viewHorizon'))->toBeTrue();
});

it('dte:demo crea la empresa demo de forma idempotente y no corre en producción', function () {
    $this->artisan('dte:demo --clave=corta')->assertFailed();
    $this->artisan('dte:demo --clave=ClaveDemo2026')->expectsOutputToContain('Demo lista')->assertSuccessful();
    $this->artisan('dte:demo --clave=ClaveDemo2026')->assertSuccessful();

    $empresa = tenancy()->sinAislamiento(fn () => Empresa::query()->where('nit', '06141507261015')->sole());
    expect(tenancy()->ejecutarComo($empresa->id, fn () => Producto::query()->count()))->toBe(4)
        ->and(auth()->attempt(['email' => 'contador@demo.test', 'password' => 'ClaveDemo2026']))->toBeTrue();

    app()->detectEnvironment(fn () => 'production');
    $this->artisan('dte:demo --clave=ClaveDemo2026')->assertFailed();
});
