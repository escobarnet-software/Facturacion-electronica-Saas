<?php

use App\Models\Usuario;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
| «¿Olvidó su contraseña?»: enlace por correo, de un solo uso, sin revelar qué correos existen.
*/

it('el login tiene el enlace «¿Olvidó su contraseña?» y la pantalla pide el correo', function () {
    $this->get('/login')->assertOk()->assertSee('¿Olvidó su contraseña?')->assertSee(route('password.request'), false);
    $this->get(route('password.request'))->assertOk()->assertSee('Le enviaremos un enlace');
});

it('envía el enlace al correo registrado y responde lo mismo si el correo no existe (sin enumerar usuarios)', function () {
    Notification::fake();
    $usuario = Usuario::factory()->create(['email' => 'ana@negocio.test']);

    $r1 = $this->from(route('password.request'))->post(route('password.email'), ['email' => 'ana@negocio.test']);
    $r2 = $this->from(route('password.request'))->post(route('password.email'), ['email' => 'nadie@negocio.test']);

    $r1->assertRedirect(route('password.request'))->assertSessionHas('aviso');
    $r2->assertRedirect(route('password.request'))->assertSessionHas('aviso', session('aviso'));
    expect($r2->getSession()->get('errors'))->toBeNull();
    Notification::assertSentTo($usuario, ResetPassword::class);
    Notification::assertSentTimes(ResetPassword::class, 1);
});

it('el correo está en español y el enlace permite crear una contraseña segura una sola vez', function () {
    Notification::fake();
    $usuario = Usuario::factory()->create(['email' => 'ana@negocio.test', 'email_verified_at' => null]);
    $this->post(route('password.email'), ['email' => 'ana@negocio.test']);

    $token = null;
    Notification::assertSentTo($usuario, ResetPassword::class, function (ResetPassword $n) use (&$token, $usuario) {
        $token = $n->token;
        $correo = $n->toMail($usuario);

        return $correo->subject === 'Restablecer su contraseña de Escobar Net DTE'
            && str_contains($correo->actionText, 'Crear una nueva contraseña')
            && str_contains($correo->actionUrl, '/reset-password/'.$n->token);
    });

    $this->get(route('password.reset', ['token' => $token, 'email' => 'ana@negocio.test']))->assertOk()->assertSee('Crear una nueva contraseña');
    $this->post(route('password.update'), ['token' => $token, 'email' => 'ana@negocio.test', 'password' => 'corta', 'password_confirmation' => 'corta'])
        ->assertSessionHasErrors('password');
    $this->post(route('password.update'), ['token' => $token, 'email' => 'ana@negocio.test', 'password' => 'NuevaClave2026', 'password_confirmation' => 'NuevaClave2026'])
        ->assertRedirect(route('login'));

    $usuario->refresh();
    expect(Hash::check('NuevaClave2026', $usuario->password))->toBeTrue()
        ->and($usuario->email_verified_at)->not->toBeNull();   // también activa una invitación pendiente

    // El mismo enlace ya no sirve.
    $this->post(route('password.update'), ['token' => $token, 'email' => 'ana@negocio.test', 'password' => 'OtraClave2026x', 'password_confirmation' => 'OtraClave2026x'])
        ->assertSessionHasErrors('email');
    $this->post('/login', ['email' => 'ana@negocio.test', 'password' => 'NuevaClave2026'])->assertRedirect();
    $this->assertAuthenticatedAs($usuario);
});

it('limita las solicitudes de enlace (no se puede usar para inundar un correo)', function () {
    Notification::fake();
    Usuario::factory()->create(['email' => 'ana@negocio.test']);
    $this->post(route('password.email'), ['email' => 'ana@negocio.test']);
    $this->post(route('password.email'), ['email' => 'ana@negocio.test']); // dentro de la espera del broker: no se reenvía
    Notification::assertSentTimes(ResetPassword::class, 1);

    foreach (range(1, 30) as $_) {
        $this->post(route('password.email'), ['email' => 'otro@negocio.test']);
    }
    $this->post(route('password.email'), ['email' => 'otro@negocio.test'])->assertStatus(429);
});
