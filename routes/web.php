<?php

use App\Http\Controllers\EmpresaActivaController;
use App\Http\Controllers\PortalDteController;
use App\Models\Empresa;
use App\Models\Usuario;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/panel');

// Portal público de descarga de DTE: enlaces firmados, sin sesión ni vencimiento.
Route::middleware('signed')->controller(PortalDteController::class)->group(function () {
    Route::get('/dte/{codigo}', 'mostrar')->name('dte.portal');
    Route::get('/dte/{codigo}/json', 'json')->name('dte.portal.json');
    Route::get('/dte/{codigo}/pdf', 'pdf')->name('dte.portal.pdf');
});

Route::middleware('auth')->group(function () {
    // Pantalla para activar el doble factor (accesible aunque aún no lo tenga).
    Route::view('/seguridad/2fa', 'auth.dos-factores')->name('seguridad.2fa');

    // 'empresa' va primero: fija el usuario en la sesión de BD para que RLS deje leer sus membresías.
    Route::middleware(['empresa', '2fa'])->group(function () {
        Route::get('/panel', function (Tenancy $tenancy) {
            /** @var Usuario $usuario */
            $usuario = auth()->user();

            return view('panel.inicio', [
                'empresa' => $tenancy->hayEmpresa() ? Empresa::query()->find($tenancy->empresaId()) : null,
                'empresas' => $usuario->empresas()->orderBy('razon_social')->get(),
            ]);
        })->name('panel');

        Route::post('/empresa-activa', EmpresaActivaController::class)->name('empresa-activa');
    });
});
