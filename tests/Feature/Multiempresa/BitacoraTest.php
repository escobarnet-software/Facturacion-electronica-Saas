<?php

use App\Models\Bitacora;
use Illuminate\Support\Facades\DB;

/*
| Terminado cuando (Paso 1): la bitácora rechaza UPDATE y DELETE.
*/

beforeEach(function () {
    $this->datos = crearEmpresaDePrueba();
    tenancy()->establecer($this->datos['empresa']->id);
    $this->entrada = Bitacora::registrar('credenciales.cargadas', $this->datos['credencial'], ['ambiente' => '00']);
});

it('registra quién hizo qué, sobre qué y cuándo', function () {
    $e = Bitacora::query()->findOrFail($this->entrada->id);

    expect($e->empresa_id)->toBe($this->datos['empresa']->id)
        ->and($e->accion)->toBe('credenciales.cargadas')
        ->and($e->entidad_id)->toBe($this->datos['credencial']->id)
        ->and($e->datos)->toBe(['ambiente' => '00'])
        ->and($e->created_at)->not->toBeNull();
});

it('rechaza UPDATE en la base de datos', function () {
    fallaEnBaseDeDatos(fn () => DB::update("UPDATE bitacora SET accion = 'alterada' WHERE id = ?", [$this->entrada->id]));

    expect(Bitacora::query()->find($this->entrada->id)->accion)->toBe('credenciales.cargadas');
});

it('rechaza DELETE y TRUNCATE en la base de datos', function () {
    fallaEnBaseDeDatos(fn () => DB::delete('DELETE FROM bitacora WHERE id = ?', [$this->entrada->id]));
    fallaEnBaseDeDatos(fn () => DB::statement('TRUNCATE bitacora'));

    expect(Bitacora::query()->find($this->entrada->id))->not->toBeNull();
});

it('el modelo se niega a actualizar o borrar', function () {
    $e = Bitacora::query()->findOrFail($this->entrada->id);

    expect(fn () => $e->update(['accion' => 'alterada']))->toThrow(LogicException::class)
        ->and(fn () => $e->delete())->toThrow(LogicException::class);
});
