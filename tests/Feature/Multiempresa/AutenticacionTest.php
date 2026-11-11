<?php

use App\Enums\Rol;
use App\Models\Bitacora;
use App\Models\Usuario;
use PragmaRX\Google2FA\Google2FA;

/*
| Login con 2FA obligatoria para dueños y superadmin; cambio de empresa activa.
*/

it('muestra el login en español', function () {
    $this->get('/login')->assertOk()->assertSee('Iniciar sesión');
});

it('un cajero entra sin 2FA y ve su empresa', function () {
    $d = crearEmpresaDePrueba(Rol::Cajero);
    $otra = crearEmpresaDePrueba();

    $this->post('/login', ['email' => $d['usuario']->email, 'password' => 'password'])
        ->assertRedirect('/panel');

    $this->get('/panel')->assertOk()
        ->assertSee($d['empresa']->nit)
        ->assertDontSee($otra['empresa']->nit);
});

it('un dueño sin 2FA no puede entrar al panel hasta activarla', function () {
    $d = crearEmpresaDePrueba(Rol::Dueno);

    $this->actingAs($d['usuario'])->get('/panel')->assertRedirect(route('seguridad.2fa'));
    $this->actingAs($d['usuario'])->getJson('/panel')->assertForbidden();
    $this->actingAs($d['usuario'])->get(route('seguridad.2fa'))->assertOk()->assertSee('Activar');
});

it('el superadmin sin 2FA tampoco puede entrar', function () {
    $admin = Usuario::factory()->superadmin()->create();

    $this->actingAs($admin)->get('/panel')->assertRedirect(route('seguridad.2fa'));
});

it('un dueño con 2FA debe dar el código de 6 dígitos al iniciar sesión', function () {
    $dueno = Usuario::factory()->con2fa()->create();
    $d = crearEmpresaDePrueba(Rol::Dueno, $dueno);

    $this->post('/login', ['email' => $dueno->email, 'password' => 'password'])
        ->assertRedirect('/two-factor-challenge');
    $this->assertGuest();

    $this->post('/two-factor-challenge', ['code' => '000000']);
    $this->assertGuest();

    $codigo = (new Google2FA)->getCurrentOtp('JBSWY3DPEHPK3PXP');
    $this->post('/two-factor-challenge', ['code' => $codigo])->assertRedirect('/panel');
    $this->assertAuthenticatedAs($dueno);

    $this->get('/panel')->assertOk()->assertSee($d['empresa']->nit);
});

it('un contador cambia de empresa activa entre las suyas, y no a otras', function () {
    $a = crearEmpresaDePrueba();
    $c = crearEmpresaDePrueba(Rol::Contador, $a['usuario']);
    $ajena = crearEmpresaDePrueba();
    $usuario = $a['usuario'];

    $this->actingAs($usuario)->get('/panel')->assertSee($a['empresa']->nit);

    $this->actingAs($usuario)->post('/empresa-activa', ['empresa_id' => $c['empresa']->id])->assertRedirect('/panel');
    expect($usuario->fresh()->empresa_activa_id)->toBe($c['empresa']->id);
    $this->actingAs($usuario)->get('/panel')->assertSee($c['empresa']->nit)->assertDontSee($a['empresa']->nit);

    $this->actingAs($usuario)->post('/empresa-activa', ['empresa_id' => $ajena['empresa']->id])->assertForbidden();
    expect($usuario->fresh()->empresa_activa_id)->toBe($c['empresa']->id);

    tenancy()->establecer($c['empresa']->id);
    expect(Bitacora::query()->where('accion', 'empresa_activa.cambiada')->count())->toBe(1);
});

it('la empresa activa se limpia al terminar la petición', function () {
    $d = crearEmpresaDePrueba(Rol::Cajero);
    $this->actingAs($d['usuario'])->get('/panel')->assertOk();

    expect(tenancy()->empresaId())->toBeNull();
});
