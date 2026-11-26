<?php

use App\Enums\Rol;
use App\Livewire\Panel\Usuarios;
use App\Mail\AccesoEmpresa;
use App\Models\Bitacora;
use App\Models\EmpresaUsuario;
use App\Models\Plan;
use App\Models\Suscripcion;
use App\Models\Usuario;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();
    $this->d = crearEmpresaDePrueba(Rol::Dueno);
    $this->d['usuario']->forceFill(['two_factor_confirmed_at' => now()])->save();
    tenancy()->establecer($this->d['empresa']->id);
    $this->actingAs($this->d['usuario']);
});

it('el dueño invita a un cajero nuevo: se crea la cuenta, se asigna la caja y recibe la invitación de un solo uso', function () {
    Livewire::test(Usuarios::class)
        ->set('nuevo', ['nombre' => 'Ana Cajera', 'email' => 'ANA@negocio.test ', 'rol' => 'cajero', 'punto_venta_id' => (string) $this->d['puntoVenta']->id])
        ->call('invitar')->assertHasNoErrors()->assertSee('Se envió la invitación a ana@negocio.test');

    $ana = Usuario::query()->where('email', 'ana@negocio.test')->sole();
    expect($ana->email_verified_at)->toBeNull()
        ->and($ana->rolEn($this->d['empresa']->id))->toBe(Rol::Cajero)
        ->and(EmpresaUsuario::query()->where('usuario_id', $ana->id)->value('punto_venta_id'))->toBe($this->d['puntoVenta']->id)
        ->and(Bitacora::query()->where('accion', 'usuario.invitado')->exists())->toBeTrue();

    $enlace = null;
    Mail::assertQueued(AccesoEmpresa::class, function (AccesoEmpresa $m) use (&$enlace) {
        $enlace = $m->enlace;

        return $m->hasTo('ana@negocio.test') && $m->cuentaNueva && $m->rol === 'Cajero';
    });
    auth()->logout();
    tenancy()->limpiar();
    $this->post($enlace, ['password' => 'ClaveCajera2026', 'password_confirmation' => 'ClaveCajera2026'])->assertRedirect(route('panel'));
    $this->get(route('panel.caja'))->assertOk();          // el cajero entra a su caja
    $this->get(route('panel.usuarios'))->assertForbidden(); // y no a la gestión de usuarios
});

it('si la persona ya tiene cuenta, solo se le da acceso y se le avisa (sin crear otra cuenta)', function () {
    $existente = Usuario::factory()->create(['email' => 'contador@estudio.test']);

    Livewire::test(Usuarios::class)->set('nuevo', ['nombre' => 'Contador', 'email' => 'contador@estudio.test', 'rol' => 'contador', 'punto_venta_id' => null])
        ->call('invitar')->assertHasNoErrors();

    expect(Usuario::query()->where('email', 'contador@estudio.test')->count())->toBe(1)
        ->and($existente->rolEn($this->d['empresa']->id))->toBe(Rol::Contador);
    Mail::assertQueued(AccesoEmpresa::class, fn (AccesoEmpresa $m) => ! $m->cuentaNueva && $m->enlace === route('login'));

    Livewire::test(Usuarios::class)->set('nuevo', ['nombre' => 'Contador', 'email' => 'contador@estudio.test', 'rol' => 'contador', 'punto_venta_id' => null])
        ->call('invitar')->assertHasErrors('nuevo.email');   // ya tiene acceso
});

it('valida los datos de la invitación y la caja del cajero', function () {
    Livewire::test(Usuarios::class)->set('nuevo', ['nombre' => 'X', 'email' => 'no-es-correo', 'rol' => 'jefe', 'punto_venta_id' => null])
        ->call('invitar')->assertHasErrors(['nuevo.nombre', 'nuevo.email', 'nuevo.rol']);
    Livewire::test(Usuarios::class)->set('nuevo', ['nombre' => 'Cajero', 'email' => 'c@negocio.test', 'rol' => 'cajero', 'punto_venta_id' => null])
        ->call('invitar')->assertHasErrors(['nuevo.punto_venta_id' => 'required']);

    // Una caja de OTRA empresa no se puede asignar.
    $otra = crearEmpresaDePrueba();
    tenancy()->establecer($this->d['empresa']->id);
    Livewire::test(Usuarios::class)->set('nuevo', ['nombre' => 'Cajero', 'email' => 'c@negocio.test', 'rol' => 'cajero', 'punto_venta_id' => (string) $otra['puntoVenta']->id])
        ->call('invitar')->assertStatus(404);
    expect(Usuario::query()->where('email', 'c@negocio.test')->exists())->toBeFalse();
});

it('cambia el rol, quita el acceso y nunca deja la empresa sin dueño ni permite quitarse a sí mismo', function () {
    $contador = Usuario::factory()->create();
    $this->d['empresa']->usuarios()->attach($contador->id, ['rol' => Rol::Contador->value]);

    Livewire::test(Usuarios::class)->call('editar', $contador->id)->set('edicion.rol', 'consulta')->call('guardarRol')->assertSet('mensaje', 'Rol actualizado.');
    expect($contador->rolEn($this->d['empresa']->id))->toBe(Rol::Consulta);

    Livewire::test(Usuarios::class)->call('editar', $this->d['usuario']->id)->set('edicion.rol', 'contador')->call('guardarRol')
        ->assertSet('error', 'La empresa debe tener al menos un dueño: asigne otro dueño antes de cambiar este rol.');
    Livewire::test(Usuarios::class)->call('quitar', $this->d['usuario']->id)->assertSet('error', 'No puede quitarse su propio acceso.');

    Livewire::test(Usuarios::class)->call('quitar', $contador->id)->assertSet('error', null);
    expect($contador->rolEn($this->d['empresa']->id))->toBeNull();
});

it('respeta el límite de usuarios del plan y solo el dueño administra usuarios', function () {
    $plan = Plan::query()->create(['codigo' => 'mini', 'nombre' => 'Mini', 'precio_mensual' => '5.00', 'max_usuarios' => 1]);
    Suscripcion::query()->create(['plan_id' => $plan->id, 'inicio' => now()->toDateString()]);

    Livewire::test(Usuarios::class)->set('nuevo', ['nombre' => 'Otro', 'email' => 'otro@negocio.test', 'rol' => 'consulta', 'punto_venta_id' => null])
        ->call('invitar')->assertSet('error', 'Su plan permite 1 usuario(s). Cambie de plan para agregar más.');

    $contador = Usuario::factory()->create();
    $this->d['empresa']->usuarios()->attach($contador->id, ['rol' => Rol::Contador->value]);
    $this->actingAs($contador);
    $this->get(route('panel.usuarios'))->assertForbidden();
});

it('no muestra ni permite invitar al superadmin de la plataforma', function () {
    Usuario::factory()->create(['email' => 'admin@escobarnet.test', 'es_superadmin' => true]);

    Livewire::test(Usuarios::class)->set('nuevo', ['nombre' => 'Admin', 'email' => 'admin@escobarnet.test', 'rol' => 'contador', 'punto_venta_id' => null])
        ->call('invitar')->assertHasErrors('nuevo.email');
});
