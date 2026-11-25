<?php

use App\Enums\Rol;
use App\Models\Producto;

/*
| Regresión: las acciones de Livewire llegan por POST /livewire/update, fuera de la ruta del panel.
| Deben volver a fijar la empresa activa (si no, EmpresaScope falla cerrado y todo da 404).
| Esta prueba va por HTTP real, no por Livewire::test().
*/

it('una acción de Livewire por HTTP conserva la empresa activa', function () {
    $d = crearEmpresaDePrueba(Rol::Contador);
    $producto = tenancy()->ejecutarComo($d['empresa']->id, fn () => Producto::query()->create(['descripcion' => 'Pupusa revuelta', 'precio' => '0.66371681']));
    tenancy()->limpiar();
    $this->actingAs($d['usuario']);

    $html = $this->get(route('panel.emitir'))->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', $html, $snap);
    preg_match('/data-update-uri="([^"]+)"/', $html, $uri);
    preg_match('/data-csrf="([^"]+)"/', $html, $csrf);
    expect($snap)->not->toBeEmpty()->and($uri)->not->toBeEmpty();
    tenancy()->limpiar();

    $r = $this->postJson($uri[1], [
        '_token' => $csrf[1],
        'components' => [[
            'snapshot' => html_entity_decode($snap[1]),
            'updates' => new stdClass,
            'calls' => [['path' => '', 'method' => 'agregarProducto', 'params' => [(string) $producto->id]]],
        ]],
    ], ['X-Livewire' => 'true']);

    $r->assertOk();
    expect($r->getContent())->toContain('Pupusa revuelta');
});
