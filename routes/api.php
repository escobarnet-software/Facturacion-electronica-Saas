<?php

use App\Http\Controllers\Api\V1\CatalogoController;
use App\Http\Controllers\Api\V1\DteController;
use Illuminate\Support\Facades\Route;

/*
| API pública v1 (ESPECIFICACION §19.3). Autenticación por token de empresa (Sanctum) con permisos por token.
| Documentación: /api/v1/openapi.json
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('openapi.json', fn () => response((string) file_get_contents(base_path('docs/api/openapi.json')), 200, ['Content-Type' => 'application/json']))->name('openapi');

    Route::middleware(['auth:sanctum', 'api.empresa', 'throttle:api'])->group(function () {
        Route::post('dte/{tipo}', [DteController::class, 'emitir'])->where('tipo', '\d{2}')->middleware('abilities:dte:emitir')->name('dte.emitir');
        Route::get('dte/{codigo}', [DteController::class, 'mostrar'])->where('codigo', '[0-9A-Fa-f-]{36}')->middleware('abilities:dte:leer')->name('dte.mostrar');
        Route::get('dte/{codigo}/pdf', [DteController::class, 'pdf'])->where('codigo', '[0-9A-Fa-f-]{36}')->middleware('abilities:dte:leer')->name('dte.pdf');
        Route::get('dte/{codigo}/json', [DteController::class, 'json'])->where('codigo', '[0-9A-Fa-f-]{36}')->middleware('abilities:dte:leer')->name('dte.json');
        Route::post('dte/{codigo}/invalidar', [DteController::class, 'invalidar'])->where('codigo', '[0-9A-Fa-f-]{36}')->middleware('abilities:dte:invalidar')->name('dte.invalidar');
        Route::get('catalogos/{catalogo}', CatalogoController::class)->middleware('abilities:catalogos:leer')->name('catalogos');
    });
});
