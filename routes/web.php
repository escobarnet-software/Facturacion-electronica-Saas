<?php

use App\Http\Controllers\EmpresaActivaController;
use App\Models\Empresa;
use App\Models\Usuario;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/panel');

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
