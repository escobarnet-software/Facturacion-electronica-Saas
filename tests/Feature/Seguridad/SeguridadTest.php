<?php

use App\Dte\Credenciales\AlmacenCertificados;
use App\Dte\Emision\EmisionDte;
use App\Dte\Emision\LineaSolicitud;
use App\Dte\Representacion\Entregador;
use App\Dte\Representacion\RepresentacionGrafica;
use App\Enums\Rol;
use App\Http\Middleware\ProtegerFormularios;
use App\Livewire\Panel\Caja;
use App\Livewire\Panel\Clientes;
use App\Livewire\Panel\Documentos;
use App\Livewire\Panel\Invalidaciones;
use App\Livewire\Panel\Onboarding;
use App\Livewire\Panel\Productos;
use App\Livewire\Panel\Recibidos;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\Usuario;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
| Pruebas de seguridad (OWASP Top 10 / ASVS nivel 2) sobre la aplicación real.
| Cada prueba intenta un ataque concreto y comprueba que se bloquea.
*/

const MH_SEG = 'https://apitest.dtes.mh.gob.sv';
const XSS = '<script>alert("xss")</script>';

beforeEach(function () {
    Cache::flush();
    Http::fake([
        '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'eyJhbGciOiJSUzUxMiJ9.c2Vn.ZmlybWE']),
        MH_SEG.'/seguridad/auth' => Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer t']]),
        MH_SEG.'/fesv/recepciondte' => fn (Request $r) => Http::response([
            'version' => 2, 'ambiente' => '00', 'estado' => 'PROCESADO', 'codigoGeneracion' => $r['codigoGeneracion'],
            'selloRecibido' => strtoupper(substr(sha1($r['codigoGeneracion']), 0, 40)), 'fhProcesamiento' => '25/11/2026 10:00:00',
            'clasificaMsg' => '10', 'codigoMsg' => '001', 'descripcionMsg' => 'RECIBIDO', 'observaciones' => [],
        ]),
    ]);
    $this->a = crearEmpresaDePrueba(Rol::Contador);
    $this->b = crearEmpresaDePrueba(Rol::Contador);
    tenancy()->establecer($this->a['empresa']->id);
    $this->actingAs($this->a['usuario']);
});

// ---------------------------------------------------------------- A03 Inyección: XSS

it('XSS almacenado: nombres con <script> se muestran escapados en el panel, el PDF y el portal', function () {
    Cliente::query()->create(['tipo_documento' => '13', 'num_documento' => '012345678', 'nombre' => XSS]);
    Producto::query()->create(['descripcion' => '<img src=x onerror=alert(1)>', 'precio' => '1.00', 'codigo' => '9001']);
    $dte = crearDteDePrueba($this->a);
    $dte->forceFill(['receptor_nombre' => XSS])->save();
    sellarDte($dte, now('America/El_Salvador')->format('Y-m-d H:i:s'));

    foreach (['panel.clientes', 'panel.documentos', 'panel.emitir', 'panel.caja', 'panel'] as $ruta) {
        $this->get(route($ruta))->assertOk()
            ->assertDontSee(XSS, false)->assertDontSee('<img src=x onerror=alert(1)>', false);
    }
    $this->get(route('panel.documentos'))->assertSee(e(XSS), false);

    tenancy()->establecer($this->a['empresa']->id); // cada petición limpia la empresa al terminar
    $html = app(RepresentacionGrafica::class)->html($dte->refresh(), $this->a['empresa']);
    expect($html)->not->toContain('<script>alert');

    tenancy()->limpiar();
    auth()->logout();
    $this->get(Entregador::enlacePortal($dte))->assertOk()->assertDontSee('<script>alert', false);
});

// ---------------------------------------------------------------- A03 Inyección: SQL

it('inyección SQL en filtros y búsquedas: no rompe la consulta ni devuelve datos de más', function () {
    $propio = crearDteDePrueba($this->a);
    $ajeno = tenancy()->ejecutarComo($this->b['empresa']->id, fn () => crearDteDePrueba($this->b));

    $c = Livewire::test(Documentos::class);
    foreach (["' OR '1'='1", "'; DROP TABLE dtes; --", "%' UNION SELECT password FROM usuarios --", '\\x27 OR 1=1'] as $carga) {
        $c->set('receptor', $carga)->assertOk()->assertDontSee($propio->numero_control)->assertDontSee($ajeno->numero_control);
        $c->set('receptor', null)->set('codigo', $carga)->assertOk()->assertDontSee($ajeno->numero_control)->set('codigo', null);
    }
    expect(Dte::query()->count())->toBe(1); // la tabla sigue ahí y solo se ve lo propio
});

// ---------------------------------------------------------------- A01 Control de acceso (IDOR)

it('IDOR: no se pueden ver ni descargar ni invalidar documentos de otra empresa cambiando el id', function () {
    $ajeno = tenancy()->ejecutarComo($this->b['empresa']->id, fn () => sellarDte(crearDteDePrueba($this->b), now('America/El_Salvador')->format('Y-m-d H:i:s')));
    tenancy()->limpiar();

    $this->get(route('panel.documentos.pdf', $ajeno->id))->assertNotFound();
    $this->get(route('panel.documentos.json', $ajeno->id))->assertNotFound();

    tenancy()->establecer($this->a['empresa']->id);
    Livewire::test(Invalidaciones::class)->set('dteId', $ajeno->id)->set('nombreSolicita', 'Cliente Solicitante')->set('numDocSolicita', '012345678')->call('invalidar')
        ->assertStatus(404);
    tenancy()->ejecutarComo($this->b['empresa']->id, fn () => expect($ajeno->fresh()->estado->value)->toBe('transmitido'));
});

it('IDOR en catálogos: editar el cliente o producto de otra empresa falla y no lo modifica', function () {
    [$cliente, $producto] = tenancy()->ejecutarComo($this->b['empresa']->id, fn () => [
        Cliente::query()->create(['tipo_documento' => '13', 'num_documento' => '098765432', 'nombre' => 'Cliente de B']),
        Producto::query()->create(['descripcion' => 'Producto de B', 'precio' => '5.00']),
    ]);
    tenancy()->establecer($this->a['empresa']->id);

    Livewire::test(Clientes::class)->call('editar', $cliente->id)->assertStatus(404);
    Livewire::test(Productos::class)->set('editando', $producto->id)
        ->set('form', ['codigo' => null, 'codigo_barras' => null, 'descripcion' => 'Hackeado', 'precio' => '0.01', 'tipo_venta' => 'gravada', 'tipo_item' => 1, 'uni_medida' => 59])
        ->call('guardar')->assertStatus(404);

    tenancy()->ejecutarComo($this->b['empresa']->id, function () use ($producto) {
        expect($producto->fresh()->descripcion)->toBe('Producto de B')->and($producto->fresh()->precio)->toBe('5.00000000');
    });
});

// ---------------------------------------------------------------- A04 Diseño inseguro: manipulación del estado de Livewire

it('Caja: un precio manipulado desde el navegador se ignora; se cobra el precio del catálogo', function () {
    $producto = Producto::query()->create(['descripcion' => 'Pupusa', 'precio' => '0.66371681', 'codigo' => '1001']);

    Livewire::test(Caja::class)
        ->set('codigo', '1001')->call('escanear')
        ->set('carrito.0.precio', '0.00000001')->set('carrito.0.descripcion', 'Gratis')
        ->call('cobrar')->assertSet('error', null);

    $dte = Dte::query()->sole();
    expect($dte->getRawOriginal('total_pagar'))->toBe('0.75')
        ->and($dte->json_original['cuerpoDocumento'][0]['descripcion'])->toBe('Pupusa');

    Livewire::test(Caja::class)->set('codigo', '1001')->call('escanear')->set('carrito.0.cantidad', '-5')->call('cobrar')
        ->assertSet('error', 'Revise las cantidades: deben ser números mayores que cero.');
    Livewire::test(Caja::class)->set('carrito', [['productoId' => 999999, 'cantidad' => '1', 'precio' => '1', 'descripcion' => 'x', 'codigo' => null, 'tipoItem' => 1, 'uniMedida' => 59]])
        ->call('cobrar')->assertStatus(404); // producto inexistente o de otra empresa
    expect(Dte::query()->count())->toBe(1);
});

// ---------------------------------------------------------------- A01 Escalada de privilegios por rol

it('el cajero no puede abrir ni ejecutar acciones de contador o dueño', function () {
    $cajero = Usuario::factory()->create();
    $this->a['empresa']->usuarios()->attach($cajero->id, ['rol' => Rol::Cajero->value, 'punto_venta_id' => $this->a['puntoVenta']->id]);
    $this->actingAs($cajero);

    foreach (['panel.invalidaciones', 'panel.reportes', 'panel.recibidos', 'panel.onboarding', 'panel.api', 'panel.suscripcion', 'panel.productos', 'panel.clientes', 'admin.empresas'] as $ruta) {
        $this->get(route($ruta))->assertForbidden();
    }
    $this->get(route('panel.reportes.descargar', ['libro' => 'compras', 'formato' => 'csv', 'desde' => '2026-11-01', 'hasta' => '2026-11-30']))->assertForbidden();
});

// ---------------------------------------------------------------- A08 Integridad: enlaces firmados

it('enlaces firmados: el portal y la invitación rechazan firmas alteradas o reutilizadas para otro documento', function () {
    $dte = sellarDte(crearDteDePrueba($this->a), now('America/El_Salvador')->format('Y-m-d H:i:s'));
    $otro = sellarDte(crearDteDePrueba($this->a), now('America/El_Salvador')->format('Y-m-d H:i:s'));
    tenancy()->limpiar();
    auth()->logout();

    $enlace = Entregador::enlacePortal($dte);
    $this->get($enlace)->assertOk();
    $this->get(str_replace($dte->codigo_generacion, $otro->codigo_generacion, $enlace))->assertForbidden();
    // Se altera un carácter de la firma (siempre distinto del original).
    $alterado = preg_replace_callback('/signature=([0-9a-f])/', fn ($m) => 'signature='.($m[1] === '0' ? '1' : '0'), $enlace);
    expect($alterado)->not->toBe($enlace);
    $this->get($alterado)->assertForbidden();
    $this->get(route('dte.portal.json', ['codigo' => $dte->codigo_generacion]))->assertForbidden(); // sin firma

    $usuario = Usuario::factory()->create(['email_verified_at' => null]);
    $invitacion = URL::temporarySignedRoute('invitacion', now()->addHour(), ['usuario' => $usuario->id]);
    $this->get(str_replace('/invitacion/'.$usuario->id, '/invitacion/'.$this->a['usuario']->id, $invitacion))->assertForbidden();
    $this->travel(2)->hours();
    $this->get($invitacion)->assertForbidden(); // vencida
});

// ---------------------------------------------------------------- A05 Configuración: subidas de archivos

it('subidas: rechaza scripts, archivos enormes y XML con entidades (XXE) en Recibidos y en el certificado', function () {
    Livewire::test(Recibidos::class)
        ->set('archivos', [UploadedFile::fake()->createWithContent('shell.php', '<?php system($_GET["c"]); ?>')])
        ->call('importar')->assertSet('mensajes', fn ($m) => str_contains($m[0] ?? '', 'no es un JSON válido'));
    Livewire::test(Recibidos::class)->set('archivos', [UploadedFile::fake()->create('grande.json', 2048)])
        ->call('importar')->assertHasErrors(['archivos.0']);

    $dueno = Usuario::factory()->create(['two_factor_confirmed_at' => now()]);
    $this->a['empresa']->usuarios()->attach($dueno->id, ['rol' => Rol::Dueno->value]);
    $this->actingAs($dueno);
    $xxe = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><CertificadoMH>&e;</CertificadoMH>';
    $base = fn ($archivo) => Livewire::test(Onboarding::class)->set('ambienteCredencial', '00')
        ->set('pwdApi', 'ClaveApiFalsa#2026')->set('pwdPrivada', 'LlaveFalsa#1')->set('certificado', $archivo)->call('guardarCredenciales');

    $base(UploadedFile::fake()->createWithContent('cert.crt', $xxe))->assertHasErrors(['certificado']);
    $base(UploadedFile::fake()->createWithContent('cert.php', '<CertificadoMH/>'))->assertHasErrors(['certificado']);
    $base(UploadedFile::fake()->createWithContent('cert.crt', 'esto no es XML'))->assertHasErrors(['certificado']);
    expect(fn () => AlmacenCertificados::validar($xxe))->toThrow(InvalidArgumentException::class);
    $base(UploadedFile::fake()->createWithContent('cert.crt', '<CertificadoMH><nit>0614</nit></CertificadoMH>'))->assertHasNoErrors();
});

// ---------------------------------------------------------------- A02 / A09: secretos

it('los secretos de los clientes nunca aparecen en pantallas, respuestas JSON ni en el log', function () {
    $dueno = Usuario::factory()->create(['two_factor_confirmed_at' => now()]);
    $this->a['empresa']->usuarios()->attach($dueno->id, ['rol' => Rol::Dueno->value]);
    $this->actingAs($dueno);
    $log = storage_path('logs/laravel.log');
    @file_put_contents($log, '');

    $this->get(route('panel.onboarding'))->assertOk()->assertDontSee('ClaveApiFalsa#2026')->assertDontSee('LlavePrivadaFalsa#2026');
    tenancy()->establecer($this->a['empresa']->id);
    emitirSeg($this->a);
    $credencial = $this->a['credencial']->fresh();
    expect(json_encode($credencial))->not->toContain('ClaveApiFalsa')->and((string) print_r($credencial, true))->not->toContain('ClaveApiFalsa');
    expect((string) @file_get_contents($log))->not->toContain('ClaveApiFalsa')->not->toContain('LlavePrivadaFalsa');
});

function emitirSeg(array $d): Dte
{
    return app(EmisionDte::class)->emitir($d['empresa'], $d['establecimiento'], $d['puntoVenta'], '01', [new LineaSolicitud('Café', '1', '0.70796460')]);
}

// ---------------------------------------------------------------- A07 Autenticación y sesión

it('autenticación: mismo mensaje para correo inexistente y contraseña errónea, y la sesión se regenera al entrar', function () {
    auth()->logout();
    $this->post('/login', ['email' => 'no-existe@prueba.test', 'password' => 'x'])->assertSessionHasErrors(['email' => 'Estas credenciales no coinciden con nuestros registros.']);
    $this->post('/login', ['email' => $this->a['usuario']->email, 'password' => 'incorrecta'])->assertSessionHasErrors(['email' => 'Estas credenciales no coinciden con nuestros registros.']);

    $this->get('/login');
    $antes = session()->getId();
    $this->post('/login', ['email' => $this->a['usuario']->email, 'password' => 'password'])->assertRedirect();
    expect(session()->getId())->not->toBe($antes);   // sin fijación de sesión

    $this->post('/logout');
    $this->assertGuest();
    $this->get(route('panel'))->assertRedirect('/login');
});

it('después del login nunca redirige a un sitio externo (open redirect)', function () {
    auth()->logout();
    $this->get('/login?redirect=https://sitio-malicioso.test')->assertOk();
    $r = $this->post('/login', ['email' => $this->a['usuario']->email, 'password' => 'password']);
    expect((string) $r->headers->get('Location'))->toStartWith(config('app.url'))->not->toContain('sitio-malicioso');
});

it('las rutas que cambian datos exigen token CSRF (salvo el buzón con token propio)', function () {
    foreach (Route::getRoutes()->getRoutes() as $ruta) {
        $metodos = array_diff($ruta->methods(), ['GET', 'HEAD', 'OPTIONS']);
        if ($metodos === [] || str_starts_with($ruta->uri(), 'api/') || str_starts_with($ruta->uri(), 'horizon')) {
            continue;
        }
        if ($ruta->uri() === 'buzon/{token}') {
            continue; // protegido por su token secreto y límite de peticiones
        }
        expect(in_array('web', $ruta->gatherMiddleware(), true))->toBeTrue("La ruta {$ruta->uri()} no está en el grupo web (CSRF)");
    }
    expect(app(Kernel::class)->getMiddlewareGroups()['web'])->toContain(ProtegerFormularios::class);
    expect(is_subclass_of(ProtegerFormularios::class, PreventRequestForgery::class))->toBeTrue();
});

it('el usuario no puede convertirse en superadmin por asignación masiva', function () {
    $u = new Usuario;
    $u->fill(['nombre' => 'X', 'email' => 'x@prueba.test', 'password' => 'secreto123', 'es_superadmin' => true, 'empresa_activa_id' => $this->b['empresa']->id]);
    expect($u->es_superadmin)->toBeNull()->and($u->empresa_activa_id)->toBeNull();
});

it('rutas con parámetros manipulados (recorrido de directorios, códigos inválidos) responden 404 sin errores', function () {
    // Nunca 200 ni 500: se rechaza (403/404) sin mostrar nada.
    $rechazada = fn ($r) => expect($r->status())->toBeIn([403, 404], 'Respuesta '.$r->status());
    $rechazada($this->get('/panel/documentos/..%2F..%2F.env/pdf'));
    $rechazada($this->get('/panel/reportes/../../.env.csv'));
    tenancy()->limpiar();
    auth()->logout();
    $rechazada($this->getJson('/api/v1/dte/..%2F..%2Fetc%2Fpasswd', ['Authorization' => 'Bearer x']));
    $rechazada($this->get('/.env'));
    $rechazada($this->get('/storage/app/private/certificados/06140101851014.crt'));
    $rechazada($this->get('/storage/06141507261015/2026/11/archivo.json'));
});

it('las cookies de sesión son HttpOnly y SameSite, y no se expone la versión de PHP', function () {
    auth()->logout();
    $r = $this->get('/login');
    $cookie = collect($r->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));
    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and(strtolower((string) $cookie->getSameSite()))->toBe('lax')
        ->and($r->headers->has('X-Powered-By'))->toBeFalse()
        ->and(collect($r->headers->getCookies())->contains(fn ($c) => $c->getName() === 'XSRF-TOKEN'))->toBeFalse(); // sin cookie legible por JS
});

it('el doble factor es obligatorio para el dueño antes de ver el panel', function () {
    $dueno = Usuario::factory()->create(['two_factor_confirmed_at' => null]);
    $this->a['empresa']->usuarios()->attach($dueno->id, ['rol' => Rol::Dueno->value]);
    $this->actingAs($dueno);

    $this->get(route('panel'))->assertRedirect(route('seguridad.2fa'));
    $this->get(route('panel.onboarding'))->assertRedirect(route('seguridad.2fa'));
});

it('una empresa no puede leer la otra ni con SQL directo (RLS), aunque se omita el scope', function () {
    tenancy()->ejecutarComo($this->b['empresa']->id, fn () => crearDteDePrueba($this->b));
    tenancy()->establecer($this->a['empresa']->id);

    expect(Dte::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(DB::select('select count(*) as n from dtes')[0]->n)->toBe(0)
        ->and(Empresa::query()->withoutGlobalScopes()->pluck('id')->all())->toBe([$this->a['empresa']->id]);
});

it('CORS: la API no autoriza orígenes cruzados por defecto ni con credenciales', function () {
    $r = $this->call('OPTIONS', '/api/v1/openapi.json', [], [], [], ['HTTP_ORIGIN' => 'https://sitio-malicioso.test', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET']);
    expect($r->headers->get('Access-Control-Allow-Origin'))->toBeNull()
        ->and($r->headers->get('Access-Control-Allow-Credentials'))->not->toBe('true');
    expect($this->get('/api/v1/openapi.json', ['Origin' => 'https://sitio-malicioso.test'])->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});
