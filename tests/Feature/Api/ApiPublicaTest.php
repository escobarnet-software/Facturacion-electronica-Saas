<?php

use App\Enums\Rol;
use App\Jobs\EnviarWebhook;
use App\Livewire\Panel\ApiWebhooks;
use App\Models\Bitacora;
use App\Models\CatValor;
use App\Models\Contingencia;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Usuario;
use App\Models\Webhook;
use App\Models\WebhookEntrega;
use App\Webhooks\DestinoWebhook;
use App\Webhooks\Webhooks;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/*
| Terminado cuando (Paso 12): las pruebas de la API cubren la emisión, la idempotencia (la misma clave da
| el mismo documento) y los permisos por token; el webhook se reintenta con backoff.
| Hacienda y el firmador siempre simulados (Http::fake): nunca se contacta a Hacienda.
*/

const MH_API = 'https://apitest.dtes.mh.gob.sv';
const HOOK_URL = 'https://hooks.cliente.test/dte';

beforeEach(function () {
    Cache::flush();
    $this->d = crearEmpresaDePrueba(Rol::Dueno);
    tenancy()->establecer($this->d['empresa']->id);
    $this->hookStatus = 200;

    Http::fake([
        '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'eyJhbGciOiJSUzUxMiJ9.YXBp.ZmlybWE']),
        MH_API.'/seguridad/auth' => Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer t']]),
        MH_API.'/fesv/recepciondte' => fn (Request $r) => Http::response([
            'version' => 2, 'ambiente' => '00', 'estado' => 'PROCESADO', 'codigoGeneracion' => $r['codigoGeneracion'],
            'selloRecibido' => strtoupper(substr(sha1($r['codigoGeneracion']), 0, 40)), 'fhProcesamiento' => '11/11/2026 10:00:00',
            'clasificaMsg' => '10', 'codigoMsg' => '001', 'descripcionMsg' => 'RECIBIDO', 'observaciones' => [],
        ]),
        MH_API.'/fesv/anulardte' => Http::response(['version' => 2, 'ambiente' => '00', 'estado' => 'PROCESADO', 'selloRecibido' => str_repeat('B', 40), 'codigoMsg' => '001', 'descripcionMsg' => 'Invalidación procesada']),
        HOOK_URL => fn () => Http::response(['ok' => true], $this->hookStatus),
    ]);
});

/** @param list<string> $permisos */
function tokenDe(Empresa $empresa, array $permisos = ['dte:emitir', 'dte:leer', 'dte:invalidar', 'catalogos:leer']): string
{
    return $empresa->createToken('Prueba', $permisos)->plainTextToken;
}

/** Petición a la API como lo haría un POS: sin sesión, solo con el token. */
function api(string $metodo, string $ruta, ?string $token, array $cuerpo = [], array $cabeceras = []): TestResponse
{
    app('auth')->forgetGuards();
    tenancy()->limpiar();
    $h = ['Accept' => 'application/json', ...($token ? ['Authorization' => 'Bearer '.$token] : []), ...$cabeceras];
    $r = test()->json($metodo, '/api/v1'.$ruta, $cuerpo, $h);
    tenancy()->limpiar();

    return $r;
}

function ventaApi(string $precio = '0.66371681'): array
{
    return ['lineas' => [['descripcion' => 'Pupusa revuelta', 'cantidad' => '6', 'precio' => $precio]]];
}

it('emite una Factura por la API: calcula, firma, transmite y devuelve el documento con sello', function () {
    $r = api('POST', '/dte/01', tokenDe($this->d['empresa']), ventaApi())->assertCreated();

    expect($r->json('data.total_pagar'))->toBe('4.50')
        ->and($r->json('data.estado'))->toBe('transmitido')
        ->and($r->json('data.sello_recibido'))->toHaveLength(40)
        ->and($r->json('data.numero_control'))->toBe('DTE-01-M001P001-000000000000001');

    $codigo = $r->json('data.codigo_generacion');
    $token = tokenDe($this->d['empresa'], ['dte:leer']);
    api('GET', "/dte/{$codigo}", $token)->assertOk()->assertJsonPath('data.codigo_generacion', $codigo);
    $json = api('GET', "/dte/{$codigo}/json", $token)->assertOk();
    expect(json_decode($json->getContent(), true)['selloRecibido'])->toHaveLength(40);
});

it('emite un Crédito Fiscal con el cliente enviado en la solicitud', function () {
    $r = api('POST', '/dte/03', tokenDe($this->d['empresa']), [
        'lineas' => [['descripcion' => 'Mercadería', 'cantidad' => '25', 'precio' => '250.00', 'descuento' => '20.00']],
        'cliente' => [
            'tipo_documento' => '36', 'num_documento' => '06140101851014', 'nrc' => '765432', 'nombre' => 'CLIENTE CONTRIBUYENTE, S.A. DE C.V.',
            'cod_actividad' => '46900', 'desc_actividad' => 'Venta al por mayor de otros productos', 'correo' => 'compras@cliente.test',
            'direccion' => ['departamento' => '05', 'municipio' => '25', 'distrito' => '01', 'complemento' => 'Santa Tecla'],
        ],
    ])->assertCreated();

    expect($r->json('data.tipo'))->toBe('03')->and($r->json('data.total_pagar'))->toBe('7039.90');
});

it('idempotencia: la misma clave da el mismo documento y nunca genera dos', function () {
    $token = tokenDe($this->d['empresa']);
    $clave = ['Idempotency-Key' => 'venta-0001'];

    $primera = api('POST', '/dte/01', $token, ventaApi(), $clave)->assertCreated();
    $segunda = api('POST', '/dte/01', $token, ventaApi(), $clave)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($segunda->json('data.codigo_generacion'))->toBe($primera->json('data.codigo_generacion'));
    tenancy()->establecer($this->d['empresa']->id);
    expect(Dte::query()->count())->toBe(1);

    api('POST', '/dte/01', $token, ventaApi('1.00'), $clave)->assertStatus(422)->assertJsonPath('message', 'Esa clave de idempotencia ya se usó con otra solicitud.');
    api('POST', '/dte/01', $token, ventaApi(), ['Idempotency-Key' => 'venta-0002'])->assertCreated();
    tenancy()->establecer($this->d['empresa']->id);
    expect(Dte::query()->count())->toBe(2);
});

it('la misma clave en otra empresa es independiente', function () {
    $b = crearEmpresaDePrueba(Rol::Dueno);
    $clave = ['Idempotency-Key' => 'misma-clave'];

    $a = api('POST', '/dte/01', tokenDe($this->d['empresa']), ventaApi(), $clave)->assertCreated();
    $rb = api('POST', '/dte/01', tokenDe($b['empresa']), ventaApi(), $clave)->assertCreated();

    expect($rb->json('data.codigo_generacion'))->not->toBe($a->json('data.codigo_generacion'));
});

it('permisos por token: sin token 401, sin el permiso 403, y nunca ve documentos de otra empresa', function () {
    api('POST', '/dte/01', null, ventaApi())->assertUnauthorized()->assertJsonPath('message', 'Token inválido o ausente.');
    api('POST', '/dte/01', 'token-inventado', ventaApi())->assertUnauthorized();

    $soloLectura = tokenDe($this->d['empresa'], ['dte:leer']);
    api('POST', '/dte/01', $soloLectura, ventaApi())->assertForbidden()->assertJsonPath('message', 'El token no tiene permiso para esta operación.');

    $codigo = api('POST', '/dte/01', tokenDe($this->d['empresa'], ['dte:emitir']), ventaApi())->assertCreated()->json('data.codigo_generacion');
    api('GET', "/dte/{$codigo}", tokenDe($this->d['empresa'], ['dte:emitir']))->assertForbidden();
    api('POST', "/dte/{$codigo}/invalidar", $soloLectura, [])->assertForbidden();
    api('GET', '/catalogos/CAT-012', $soloLectura)->assertForbidden();

    $b = crearEmpresaDePrueba(Rol::Dueno);
    api('GET', "/dte/{$codigo}", tokenDe($b['empresa']))->assertNotFound();
    api('GET', "/dte/{$codigo}/pdf", tokenDe($b['empresa']))->assertNotFound();

    // Un token revocado deja de servir.
    $empresa = tenancy()->ejecutarComo($this->d['empresa']->id, fn () => Empresa::query()->find($this->d['empresa']->id));
    $empresa->tokens()->delete();
    api('GET', "/dte/{$codigo}", $soloLectura)->assertUnauthorized();
});

it('la sesión del panel no sirve para la API', function () {
    $this->actingAs($this->d['usuario']);

    $this->getJson('/api/v1/catalogos/CAT-012')->assertUnauthorized();
});

it('con calcular=false valida los totales enviados con holgura de ±0.01', function () {
    $token = tokenDe($this->d['empresa']);

    api('POST', '/dte/01', $token, [...ventaApi(), 'calcular' => false, 'totales' => ['total_pagar' => '4.51']])->assertCreated();
    api('POST', '/dte/01', $token, [...ventaApi(), 'calcular' => false, 'totales' => ['total_pagar' => '4.52']])
        ->assertStatus(422)->assertJsonPath('message', 'Los totales enviados no cuadran con el cálculo: total_pagar calculado 4.50, enviado 4.52.');
    api('POST', '/dte/01', $token, [...ventaApi(), 'calcular' => false])->assertStatus(422)->assertJsonValidationErrors('totales');

    tenancy()->establecer($this->d['empresa']->id);
    expect(Dte::query()->count())->toBe(1); // los rechazados no consumen correlativo
});

it('acepta montos como número JSON solo si son exactos; si no, pide enviarlos como texto', function () {
    $token = tokenDe($this->d['empresa']);

    api('POST', '/dte/01', $token, ['lineas' => [['descripcion' => 'Horchata', 'cantidad' => 2, 'precio' => 0.88495575]]])
        ->assertCreated()->assertJsonPath('data.total_pagar', '2.00');
    api('POST', '/dte/01', $token, ['lineas' => [['descripcion' => 'X', 'cantidad' => 1, 'precio' => 0.1234567890123456]]])
        ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'envíelo como texto'));
    api('POST', '/dte/01', $token, ['lineas' => [['descripcion' => 'X', 'cantidad' => '1', 'precio' => '-5']]])->assertStatus(422);
    api('POST', '/dte/14', $token, ventaApi())->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'aún no está disponible'));
    api('POST', '/dte/01', $token, [])->assertStatus(422)->assertJsonPath('errors.lineas.0', 'El campo lineas es obligatorio.');
});

it('invalida por la API y dispara el webhook dte.invalidado', function () {
    tenancy()->sinAislamiento(fn () => Empresa::query()->whereKey($this->d['empresa']->id)->update([
        'responsable_nombre' => 'Responsable de Prueba', 'responsable_tipo_doc' => '13', 'responsable_num_doc' => '012345678',
    ]));
    Webhook::query()->create(['url' => HOOK_URL, 'secreto' => 'whsec_prueba', 'eventos' => ['dte.invalidado']]);
    $token = tokenDe($this->d['empresa']);
    $codigo = api('POST', '/dte/01', $token, ventaApi())->json('data.codigo_generacion');

    api('POST', "/dte/{$codigo}/invalidar", $token, [
        'tipo_anulacion' => 2, 'solicita' => ['nombre' => 'Cliente Solicitante', 'tipo_documento' => '13', 'num_documento' => '098765432'],
    ])->assertOk()->assertJsonPath('data.estado', 'invalidado')->assertJsonPath('data.evento_invalidacion.sello_recibido', str_repeat('B', 40));

    Http::assertSent(fn (Request $r) => $r->url() === HOOK_URL && $r->header('X-DTE-Evento')[0] === 'dte.invalidado');
});

it('consulta catálogos oficiales', function () {
    CatValor::query()->create(['catalogo' => 'CAT-012', 'codigo' => '06', 'valor' => 'San Salvador', 'padre' => '', 'vigente' => true, 'version' => '2']);
    api('GET', '/catalogos/012', tokenDe($this->d['empresa'], ['catalogos:leer']))->assertOk()
        ->assertJsonPath('catalogo', 'CAT-012')
        ->assertJsonFragment(['codigo' => '06']);
    api('GET', '/catalogos/CAT-999', tokenDe($this->d['empresa'], ['catalogos:leer']))->assertNotFound();
});

it('webhook: firma HMAC verificable con el secreto y cuerpo idéntico en cada intento', function () {
    $webhook = Webhook::query()->create(['url' => HOOK_URL, 'secreto' => 'whsec_prueba', 'eventos' => ['dte.transmitido']]);
    api('POST', '/dte/01', tokenDe($this->d['empresa']), ventaApi())->assertCreated();

    tenancy()->establecer($this->d['empresa']->id);
    $entrega = WebhookEntrega::query()->sole();
    expect($entrega->estado)->toBe('entregado')->and($entrega->intentos)->toBe(1)
        ->and($webhook->fresh()->getRawOriginal('secreto'))->not->toContain('whsec_prueba')   // cifrado en la BD
        ->and($webhook->toArray())->not->toHaveKey('secreto');

    Http::assertSent(function (Request $r) use ($entrega) {
        if ($r->url() !== HOOK_URL) {
            return false;
        }
        parse_str(str_replace(',', '&', $r->header('X-DTE-Firma')[0]), $f);

        return $r->body() === $entrega->cuerpo
            && hash_equals(hash_hmac('sha256', $f['t'].'.'.$r->body(), 'whsec_prueba'), $f['v1'])
            && json_decode($r->body(), true)['datos']['estado'] === 'transmitido';
    });
});

it('webhook: si el destino falla se reintenta con backoff creciente y al final queda fallido', function () {
    Webhook::query()->create(['url' => HOOK_URL, 'secreto' => 'whsec_prueba', 'eventos' => ['contingencia.iniciada']]);
    $this->hookStatus = 500;
    $c = Contingencia::query()->create(['ambiente' => '00', 'inicio' => now(), 'tipo' => 1, 'estado' => Contingencia::ACTIVA]);

    Queue::fake();
    Webhooks::contingencia('contingencia.iniciada', $c);
    $entrega = WebhookEntrega::query()->sole();
    Queue::assertPushedOn('webhooks', EnviarWebhook::class);

    // Primer intento: falla y lanza la excepción que hace que el worker lo reintente tras el backoff.
    $job = new EnviarWebhook($this->d['empresa']->id, $entrega->id);
    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'HTTP 500');
    $entrega->refresh();
    expect($entrega->intentos)->toBe(1)->and($entrega->estado)->toBe('pendiente')->and($entrega->ultimo_status)->toBe(500);

    $esperas = $job->backoff();
    expect($job->tries)->toBe(count($esperas) + 1)->and($job->queue)->toBe('webhooks');
    foreach (array_slice($esperas, 1) as $i => $espera) {
        expect($espera)->toBeGreaterThan($esperas[$i]);
    }

    // Segundo intento: el destino se recupera.
    $this->hookStatus = 200;
    tenancy()->limpiar();
    $job->middleware()[0]->handle($job, fn ($j) => $j->handle());
    tenancy()->establecer($this->d['empresa']->id);
    expect($entrega->fresh()->estado)->toBe('entregado')->and($entrega->fresh()->intentos)->toBe(2);

    // Sin conexión hasta agotar los intentos: queda fallido.
    Http::fake([HOOK_URL => fn () => throw new ConnectionException('timeout')]);
    $otra = WebhookEntrega::query()->create(['webhook_id' => $entrega->webhook_id, 'uuid' => (string) Str::uuid(), 'evento' => 'contingencia.iniciada', 'cuerpo' => '{}']);
    $job2 = new EnviarWebhook($this->d['empresa']->id, $otra->id);
    expect(fn () => $job2->handle())->toThrow(RuntimeException::class, 'sin conexión');
    $job2->failed(new RuntimeException('agotado'));
    expect($otra->fresh()->estado)->toBe('fallido')->and($otra->fresh()->intentos)->toBe(1);
});

it('no acepta webhooks hacia la red interna (SSRF)', function () {
    expect(DestinoWebhook::rechazo('https://hooks.cliente.com/dte'))->toBeNull()
        ->and(DestinoWebhook::rechazo('http://hooks.cliente.com/dte'))->toContain('https')
        ->and(DestinoWebhook::rechazo('https://localhost/x'))->not->toBeNull()
        ->and(DestinoWebhook::rechazo('https://10.0.0.5/x'))->not->toBeNull()
        ->and(DestinoWebhook::rechazo('https://169.254.169.254/latest'))->not->toBeNull()
        ->and(DestinoWebhook::rechazo('https://[::1]/x'))->not->toBeNull()
        ->and(DestinoWebhook::rechazo('https://firmador.internal/x'))->not->toBeNull();
});

it('el dueño crea un token desde el panel y lo ve una sola vez; el contador no entra', function () {
    $this->actingAs($this->d['usuario']);
    $c = Livewire::test(ApiWebhooks::class)
        ->set('nombreToken', 'POS centro')->set('permisos', ['dte:emitir'])
        ->call('crearToken')->assertHasNoErrors();
    $plano = $c->get('secretoNuevo');
    expect($plano)->toContain('|');

    api('POST', '/dte/01', $plano, ventaApi())->assertCreated();

    $this->actingAs($this->d['usuario']);
    tenancy()->establecer($this->d['empresa']->id);
    Livewire::test(ApiWebhooks::class)->set('urlWebhook', 'https://10.0.0.1/x')->set('eventos', ['dte.transmitido'])->call('crearWebhook')
        ->assertSet('error', 'La URL del webhook no puede apuntar a una dirección privada o reservada.');
    expect(Webhook::query()->count())->toBe(0);

    $contador = crearEmpresaDePrueba(Rol::Contador);
    $this->actingAs($contador['usuario']);
    tenancy()->establecer($contador['empresa']->id);
    $this->get(route('panel.api'))->assertForbidden();
});

it('el documento OpenAPI es válido y documenta todas las rutas de la API', function () {
    $doc = json_decode((string) file_get_contents(base_path('docs/api/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
    $this->getJson('/api/v1/openapi.json')->assertOk()->assertJsonPath('openapi', '3.1.0');

    $rutas = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/'));
    expect($rutas)->not->toBeEmpty();
    foreach ($rutas as $ruta) {
        $path = preg_replace('/^api\/v1/', '', $ruta->uri());
        $metodo = strtolower($ruta->methods()[0]);
        expect(isset($doc['paths'][$path][$metodo]))->toBeTrue("Falta {$metodo} {$path} en OpenAPI");
    }
    expect(array_keys($doc['webhooks']))->toBe(array_keys(Webhook::EVENTOS));
});

it('la bitácora registra las acciones de la API como «api» con el nombre del token, nunca como un usuario', function () {
    $otro = Usuario::factory()->create(); // un usuario cualquiera, para que un id coincidente no oculte el error
    $token = $this->d['empresa']->createToken('POS sucursal centro', ['dte:emitir'])->plainTextToken;

    api('POST', '/dte/01', $token, ventaApi())->assertCreated();

    tenancy()->establecer($this->d['empresa']->id);
    $registro = Bitacora::query()->where('accion', 'dte.emitido')->latest('id')->first();
    expect($registro->usuario_id)->toBeNull()
        ->and($registro->datos['via'])->toBe('api')
        ->and($registro->datos['token'])->toBe('POS sucursal centro');
    unset($otro);
});
