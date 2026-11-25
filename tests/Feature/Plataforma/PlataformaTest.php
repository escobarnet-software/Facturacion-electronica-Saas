<?php

use App\Dte\Emision\EmisionDte;
use App\Dte\Emision\LineaSolicitud;
use App\Enums\Rol;
use App\Livewire\Admin\Empresas;
use App\Livewire\Admin\Estado;
use App\Livewire\Panel\ApiWebhooks;
use App\Livewire\Panel\Establecimientos;
use App\Livewire\Panel\Suscripcion as PanelSuscripcion;
use App\Mail\AvisoConsumo;
use App\Models\AccesoSoporte;
use App\Models\Bitacora;
use App\Models\Cobro;
use App\Models\ConsumoMensual;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\HaciendaMetrica;
use App\Models\Plan;
use App\Models\Suscripcion;
use App\Models\Usuario;
use App\Plataforma\FacturadorSuscripciones;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
| Terminado cuando (Paso 13):
|  - Exceder el plan no bloquea la emisión y registra el excedente.
|  - El superadmin no puede ver credenciales.
|  - El acceso de soporte exige un permiso temporal de la empresa.
| Hacienda y el firmador siempre simulados.
*/

const MH_PLAT = 'https://apitest.dtes.mh.gob.sv';

beforeEach(function () {
    Cache::flush();
    Http::fake([
        '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'eyJhbGciOiJSUzUxMiJ9.cGxhdA.ZmlybWE']),
        MH_PLAT.'/seguridad/auth' => Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer t']]),
        MH_PLAT.'/fesv/recepciondte' => fn (Request $r) => Http::response([
            'version' => 2, 'ambiente' => '00', 'estado' => 'PROCESADO', 'codigoGeneracion' => $r['codigoGeneracion'],
            'selloRecibido' => strtoupper(substr(sha1($r['codigoGeneracion']), 0, 40)), 'fhProcesamiento' => '25/11/2026 10:00:00',
            'clasificaMsg' => '10', 'codigoMsg' => '001', 'descripcionMsg' => 'RECIBIDO', 'observaciones' => [],
        ]),
    ]);
    $this->plan = Plan::query()->create([
        'codigo' => 'basico', 'nombre' => 'Básico', 'precio_mensual' => '25.00', 'documentos_mes' => 5,
        'precio_documento_adicional' => '0.05', 'max_establecimientos' => 1, 'max_usuarios' => 3, 'incluye_api' => false,
    ]);
    $this->d = crearEmpresaDePrueba(Rol::Dueno);
    $this->d['usuario']->forceFill(['two_factor_confirmed_at' => now()])->save();
    tenancy()->establecer($this->d['empresa']->id);
    Suscripcion::query()->create(['plan_id' => $this->plan->id, 'inicio' => now()->toDateString()]);
});

function superadmin(): Usuario
{
    return Usuario::factory()->create(['es_superadmin' => true, 'two_factor_confirmed_at' => now()]);
}

function emitirFactura(array $d): Dte
{
    return app(EmisionDte::class)->emitir($d['empresa'], $d['establecimiento'], $d['puntoVenta'], '01', [new LineaSolicitud('Pupusa revuelta', '6', '0.66371681')]);
}

it('exceder el plan NO bloquea la emisión: se emite, se cuenta el excedente y se avisa al 80 % y al 100 %', function () {
    Mail::fake();
    foreach (range(1, 7) as $_) {
        expect(emitirFactura($this->d)->sello_recibido)->toHaveLength(40);
    }

    $consumo = ConsumoMensual::query()->sole();
    expect(Dte::query()->count())->toBe(7)
        ->and($consumo->documentos)->toBe(7)
        ->and($consumo->limite)->toBe(5)
        ->and($consumo->excedente())->toBe(2)
        ->and($consumo->aviso_80_at)->not->toBeNull()
        ->and($consumo->aviso_100_at)->not->toBeNull()
        ->and(Bitacora::query()->where('accion', 'plan.consumo_80')->count())->toBe(1)
        ->and(Bitacora::query()->where('accion', 'plan.consumo_100')->count())->toBe(1);
    Mail::assertQueued(AvisoConsumo::class, 2);
    Mail::assertQueued(AvisoConsumo::class, fn (AvisoConsumo $m) => $m->porcentaje === 100 && str_contains($m->render(), 'nunca se detiene'));
});

it('cobra el excedente al centavo y emite el DTE de cobro desde la empresa de Escobar Net (idempotente)', function () {
    foreach (range(1, 8) as $_) {
        emitirFactura($this->d);
    }
    $periodo = now('America/El_Salvador')->format('Y-m');
    $escobar = crearEmpresaDePrueba(Rol::Dueno);
    config(['plataforma.empresa_id' => $escobar['empresa']->id]);
    tenancy()->limpiar();

    app(FacturadorSuscripciones::class)->facturar($periodo);
    app(FacturadorSuscripciones::class)->facturar($periodo); // repetir no duplica

    tenancy()->establecer($this->d['empresa']->id);
    $cobro = Cobro::query()->sole();
    expect($cobro->getRawOriginal('cargo_plan'))->toBe('25.00')
        ->and($cobro->excedente)->toBe(3)
        ->and($cobro->getRawOriginal('cargo_excedente'))->toBe('0.15')
        ->and($cobro->getRawOriginal('subtotal'))->toBe('25.15')
        ->and($cobro->dte_codigo_generacion)->toHaveLength(36);

    tenancy()->establecer($escobar['empresa']->id);
    $dte = Dte::query()->where('codigo_generacion', $cobro->dte_codigo_generacion)->sole();
    expect($dte->tipo)->toBe('03') // el cliente es contribuyente: Crédito Fiscal
        ->and($dte->getRawOriginal('total_gravada'))->toBe('25.15')
        ->and($dte->getRawOriginal('total_pagar'))->toBe('28.42') // 25.15 + 13 % (3.2695 → 3.27)
        ->and($dte->json_original['receptor']['nit'])->toBe($this->d['empresa']->nit)
        ->and(Dte::query()->count())->toBe(1);
});

it('el superadmin no puede ver credenciales: ni en sus pantallas ni como soporte', function () {
    $admin = superadmin();
    AccesoSoporte::query()->create(['otorgado_por' => $this->d['usuario']->id, 'motivo' => 'Revisar un rechazo', 'expira_at' => now()->addHours(2)]);
    $credencial = $this->d['credencial']->fresh();
    $cifrado = (string) $credencial->getRawOriginal('pwd_api');
    tenancy()->limpiar();

    $this->actingAs($admin);
    foreach (['admin.empresas', 'admin.estado', 'admin.planes', 'admin.cobros'] as $ruta) {
        $this->get(route($ruta))->assertOk()
            ->assertDontSee('ClaveApiFalsa#2026')->assertDontSee('LlavePrivadaFalsa#2026')->assertDontSee($cifrado);
    }

    $this->post(route('admin.soporte.entrar', $this->d['empresa']->id))->assertRedirect(route('panel.documentos'));
    $this->get(route('panel.documentos'))->assertOk()->assertSee('Modo soporte');
    $this->get(route('panel.onboarding'))->assertForbidden();   // configuración y credenciales: no
    $this->get(route('panel.api'))->assertForbidden();
    $this->get(route('panel.emitir'))->assertForbidden();        // solo consulta

    expect(json_encode($credencial->toArray()))->not->toContain('ClaveApiFalsa#2026')->not->toContain($cifrado);
});

it('el acceso de soporte exige el permiso temporal vigente de la empresa', function () {
    $admin = superadmin();
    tenancy()->limpiar();
    $this->actingAs($admin);

    // Sin permiso: no entra (y tampoco puede forzar la empresa por otra vía).
    $this->post(route('admin.soporte.entrar', $this->d['empresa']->id))->assertForbidden();
    $this->get(route('panel.documentos'))->assertForbidden();

    // La empresa otorga el permiso desde su panel.
    $this->actingAs($this->d['usuario']);
    tenancy()->establecer($this->d['empresa']->id);
    Livewire::test(PanelSuscripcion::class)->set('horas', 2)->set('motivo', 'Revisar un rechazo')->call('otorgar')->assertHasNoErrors();
    Livewire::test(PanelSuscripcion::class)->set('horas', 500)->set('motivo', 'Demasiado')->call('otorgar')->assertHasErrors(['horas']);
    $acceso = AccesoSoporte::query()->sole();
    tenancy()->limpiar();

    $this->actingAs($admin);
    $this->post(route('admin.soporte.entrar', $this->d['empresa']->id))->assertRedirect();
    $this->get(route('panel.documentos'))->assertOk();
    tenancy()->establecer($this->d['empresa']->id);
    expect(Bitacora::query()->where('accion', 'soporte.acceso')->count())->toBe(1);

    // Al vencer el permiso, pierde el acceso aunque siga en la sesión.
    $acceso->forceFill(['expira_at' => now()->subMinute()])->save();
    tenancy()->limpiar();
    $this->get(route('panel.documentos'))->assertForbidden();

    // Revocado: igual.
    tenancy()->establecer($this->d['empresa']->id);
    $acceso->forceFill(['expira_at' => now()->addHour(), 'revocado_at' => now()])->save();
    tenancy()->limpiar();
    $this->post(route('admin.soporte.entrar', $this->d['empresa']->id))->assertForbidden();

    // Un usuario normal no entra al superadmin ni como soporte.
    $this->actingAs($this->d['usuario']);
    $this->get(route('admin.empresas'))->assertForbidden();
    $this->post(route('admin.soporte.entrar', $this->d['empresa']->id))->assertForbidden();
});

it('el superadmin da de alta, cambia el plan y suspende empresas; la suspendida no entra al panel ni a la API', function () {
    $admin = superadmin();
    $premium = Plan::query()->create(['codigo' => 'pro', 'nombre' => 'Pro', 'precio_mensual' => '60.00', 'incluye_api' => true]);
    tenancy()->limpiar();
    $this->actingAs($admin);

    Livewire::test(Empresas::class)
        ->set('nueva', ['nit' => '06142803901121', 'nrc' => '2345678', 'razon_social' => 'NUEVA, S.A. DE C.V.', 'correo' => 'info@nueva.test', 'plan_id' => (string) $this->plan->id, 'dueno_email' => 'duena@nueva.test', 'dueno_nombre' => 'Dueña Nueva'])
        ->call('crear')->assertHasNoErrors()
        ->assertSet('invitacionEnviada', 'duena@nueva.test')
        ->call('cambiarPlan', $this->d['empresa']->id, $premium->id)
        ->call('cambiarEstado', $this->d['empresa']->id, 'suspendida');

    $nueva = tenancy()->sinAislamiento(fn () => Empresa::query()->where('nit', '06142803901121')->sole());
    $duena = Usuario::query()->where('email', 'duena@nueva.test')->sole();
    expect(tenancy()->ejecutarComo($nueva->id, fn () => $duena->rolEn($nueva->id)))->toBe(Rol::Dueno);
    tenancy()->establecer($this->d['empresa']->id);
    expect(Suscripcion::query()->where('estado', 'activa')->sole()->plan_id)->toBe($premium->id);
    $token = $this->d['empresa']->fresh()->createToken('POS', ['dte:leer'])->plainTextToken;
    tenancy()->limpiar();

    $this->actingAs($this->d['usuario']);
    $this->get(route('panel'))->assertForbidden()->assertSee('suspendida');
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/catalogos/CAT-012', ['Authorization' => 'Bearer '.$token])->assertForbidden()->assertJsonPath('message', 'La cuenta de la empresa está suspendida. Comuníquese con Escobar Net.');
});

it('los límites del plan se aplican al configurar (establecimientos y API), nunca al emitir', function () {
    $this->actingAs($this->d['usuario']);

    Livewire::test(Establecimientos::class)
        ->set('establecimiento', ['tipo' => '01', 'nombre' => 'Sucursal 2', 'cod_estable_mh' => 'S002'])
        ->call('crearEstablecimiento')->assertHasErrors(['establecimiento.nombre']);
    Livewire::test(ApiWebhooks::class)->set('nombreToken', 'POS')->set('permisos', ['dte:emitir'])->call('crearToken')
        ->assertSet('error', 'Su plan no incluye la API. Cambie de plan para conectar su POS o ERP.')->assertSet('secretoNuevo', null);
});

it('registra latencia y errores de Hacienda por endpoint y los muestra al superadmin', function () {
    emitirFactura($this->d);
    $metricas = HaciendaMetrica::query()->pluck('endpoint')->all();
    expect($metricas)->toContain('/seguridad/auth')->toContain('/fesv/recepciondte')
        ->and(HaciendaMetrica::query()->where('endpoint', '/fesv/recepciondte')->value('codigo_msg'))->toBe('001');

    tenancy()->limpiar();
    $this->actingAs(superadmin());
    Livewire::test(Estado::class)->assertSee('/fesv/recepciondte')->assertSee('Contingencias activas');
});
