<?php

use App\Enums\Rol;
use App\Livewire\Panel\MiCuenta;
use App\Models\Usuario;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    // Contador: no exige doble factor, así se prueba la pantalla sin activarlo.
    $this->d = crearEmpresaDePrueba(Rol::Contador);
    tenancy()->establecer($this->d['empresa']->id);
});

it('todos los usuarios ven «Mi cuenta» en el menú', function () {
    $this->actingAs($this->d['usuario']);
    $this->get(route('panel'))->assertOk()->assertSee(route('panel.cuenta'), false);
    $this->get(route('panel.cuenta'))->assertOk()->assertSee('Cambiar contraseña');
});

it('cambia la contraseña solo con la actual correcta y una nueva segura', function () {
    $usuario = $this->d['usuario'];
    $usuario->forceFill(['password' => Hash::make('Anterior#2026x')])->save();
    $this->actingAs($usuario);

    Livewire::test(MiCuenta::class)
        ->set('actual', 'Equivocada#2026')->set('nueva', 'NuevaClave2026x')->set('nueva_confirmation', 'NuevaClave2026x')
        ->call('cambiarContrasena')->assertHasErrors('actual');
    Livewire::test(MiCuenta::class)
        ->set('actual', 'Anterior#2026x')->set('nueva', 'corta')->set('nueva_confirmation', 'corta')
        ->call('cambiarContrasena')->assertHasErrors('nueva');
    Livewire::test(MiCuenta::class)
        ->set('actual', 'Anterior#2026x')->set('nueva', 'NuevaClave2026x')->set('nueva_confirmation', 'NuevaClave2026x')
        ->call('cambiarContrasena')->assertHasNoErrors();

    expect(Hash::check('NuevaClave2026x', (string) $usuario->fresh()->password))->toBeTrue();
});

it('muestra los códigos de recuperación solo con la contraseña', function () {
    $usuario = $this->d['usuario'];
    $usuario->forceFill([
        'password' => Hash::make('Clave#2026xyz'),
        'two_factor_secret' => encrypt('SECRETOFALSO2026'),
        'two_factor_recovery_codes' => encrypt(json_encode(['codigo-uno-falso', 'codigo-dos-falso'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $this->actingAs($usuario);

    Livewire::test(MiCuenta::class)->set('claveCodigos', 'Equivocada#1')->call('verCodigos')
        ->assertHasErrors('claveCodigos')->assertDontSee('codigo-uno-falso');
    Livewire::test(MiCuenta::class)->set('claveCodigos', 'Clave#2026xyz')->call('verCodigos')
        ->assertSee('codigo-uno-falso');
    Livewire::test(MiCuenta::class)->set('claveCodigos', 'Clave#2026xyz')->call('generarCodigos')
        ->assertDontSee('codigo-uno-falso');
    expect($usuario->fresh()->recoveryCodes())->not->toContain('codigo-uno-falso');
});

it('el comando reinicia el doble factor de un usuario', function () {
    $usuario = $this->d['usuario'];
    $usuario->forceFill(['two_factor_secret' => encrypt('SECRETOFALSO2026'), 'two_factor_confirmed_at' => now()])->save();

    $this->artisan('plataforma:2fa:reiniciar', ['email' => strtoupper($usuario->email)])->assertSuccessful();
    $this->artisan('plataforma:2fa:reiniciar', ['email' => 'nadie@ejemplo.test'])->assertFailed();

    $u = Usuario::query()->find($usuario->id);
    expect($u->two_factor_secret)->toBeNull()->and($u->two_factor_confirmed_at)->toBeNull();
});
