<?php

use App\Console\Commands\ProbarCorreo;
use App\Livewire\Admin\Estado;
use App\Mail\AccesoEmpresa;
use App\Mail\AlertaPlataforma;
use App\Mail\InvitacionDueno;
use App\Models\Usuario;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/*
| Envío de correos reales: configuración SMTP por variables y comando de prueba.
| En las pruebas se usa el transporte «array» (no sale a internet).
*/

it('el comando de prueba envía un correo con la configuración actual y no muestra la contraseña', function () {
    config(['mail.default' => 'array', 'mail.mailers.array.host' => 'smtp.ejemplo.test', 'mail.from.address' => 'facturas@ejemplo.test']);
    $enviados = [];
    Event::listen(MessageSent::class, function ($e) use (&$enviados) {
        $enviados[] = $e->message;
    });

    $this->artisan('plataforma:correo:probar', ['destino' => 'cliente@ejemplo.test'])
        ->expectsOutputToContain('Correo de prueba enviado a cliente@ejemplo.test')->assertSuccessful();

    expect($enviados)->toHaveCount(1)
        ->and($enviados[0]->getTo()[0]->getAddress())->toBe('cliente@ejemplo.test')
        ->and($enviados[0]->getSubject())->toBe('Prueba de correo de Escobar Net DTE')
        ->and($enviados[0]->getFrom()[0]->getAddress())->toBe('facturas@ejemplo.test');

    $this->artisan('plataforma:correo:probar', ['destino' => 'no-es-correo'])->assertExitCode(2);
});

it('si el SMTP falla, explica la causa en español sin repetir credenciales', function () {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);

    $this->artisan('plataforma:correo:probar', ['destino' => 'cliente@ejemplo.test'])
        ->expectsOutputToContain('no se pudo conectar al servidor SMTP')->assertFailed();

    expect(ProbarCorreo::explicar('Expected response code "235" but got code "535", with message "535 5.7.8 Authentication failed"'))->toContain('rechazó el usuario o la contraseña')
        ->and(ProbarCorreo::explicar('algo raro password=Secreta123 fin'))->not->toContain('Secreta123');
});

it('el superadmin ve la configuración de correo y puede enviarse una prueba', function () {
    config(['mail.default' => 'array']);
    $admin = Usuario::factory()->create(['es_superadmin' => true, 'two_factor_confirmed_at' => now()]);
    $this->actingAs($admin);

    Livewire::test(Estado::class)->assertSee('Correo')->call('probarCorreo')
        ->assertSet('resultadoCorreo', "Correo de prueba enviado a {$admin->email}.");
});

it('todos los correos de la plataforma salen en español y por la cola de entrega', function () {
    foreach ([new AccesoEmpresa('Pupusería', 'Cajero', 'Dueño', 'https://x.test', true), new InvitacionDueno('Pupusería', 'https://x.test'), new AlertaPlataforma('Prueba')] as $correo) {
        $html = $correo->render();
        expect($html)->not->toContain('Hello')->not->toContain('Regards');
    }
    expect((new AccesoEmpresa('P', 'C', 'D', 'https://x.test', false))->queue)->toBe('entrega');
});
