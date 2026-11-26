<?php

use App\Enums\Rol;
use App\Livewire\Panel\Emitir;
use Livewire\Livewire;

it('el menú sigue el flujo de trabajo y se adapta al rol', function () {
    $dueno = crearEmpresaDePrueba(Rol::Dueno);
    $dueno['usuario']->forceFill(['two_factor_confirmed_at' => now()])->save();
    tenancy()->establecer($dueno['empresa']->id);
    $this->actingAs($dueno['usuario']);

    $this->get(route('panel'))->assertOk()
        ->assertSeeInOrder(['Nuevo documento', 'Inicio', 'Caja rápida', 'Mis documentos', 'Emitidos', 'Reportes', 'Libros de IVA', 'Mi negocio', 'Configuración', 'Empresa y Hacienda'])
        ->assertSee(route('panel.cuenta'), false)
        ->assertSee('¿Qué desea hacer?')
        ->assertSee(route('panel.emitir', ['tipo' => '03']), false);
});

it('el cajero solo ve lo suyo, sin configuración ni primeros pasos', function () {
    $cajero = crearEmpresaDePrueba(Rol::Cajero);
    tenancy()->establecer($cajero['empresa']->id);
    $this->actingAs($cajero['usuario']);

    $this->get(route('panel'))->assertOk()
        ->assertSee('Nuevo documento')
        ->assertDontSee(route('panel.onboarding'), false)
        ->assertDontSee(route('panel.usuarios'), false)
        ->assertDontSee('Primeros pasos');
});

it('«Nuevo documento» puede abrir directamente un Crédito Fiscal', function () {
    $d = crearEmpresaDePrueba(Rol::Contador);
    tenancy()->establecer($d['empresa']->id);
    $this->actingAs($d['usuario']);

    Livewire::withQueryParams(['tipo' => '03'])->test(Emitir::class)->assertSet('tipo', '03');
    Livewire::withQueryParams(['tipo' => '99'])->test(Emitir::class)->assertSet('tipo', '01');
});
