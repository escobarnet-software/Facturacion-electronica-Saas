<?php

use App\Http\Controllers\BuzonEntradaController;
use App\Http\Controllers\DocumentoDescargaController;
use App\Http\Controllers\EmpresaActivaController;
use App\Http\Controllers\InvitacionController;
use App\Http\Controllers\LibroDescargaController;
use App\Http\Controllers\PortalDteController;
use App\Http\Controllers\SaludController;
use App\Http\Controllers\SoporteController;
use App\Livewire\Admin;
use App\Livewire\Panel;
use App\Models\Usuario;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/panel');

// Invitación del dueño: enlace firmado de un solo uso.
Route::middleware(['signed', 'throttle:10,1'])->controller(InvitacionController::class)->group(function () {
    Route::get('/invitacion/{usuario}', 'mostrar')->name('invitacion');
    Route::post('/invitacion/{usuario}', 'aceptar')->name('invitacion.aceptar');
});

// Healthcheck por componente (balanceador y monitoreo externo).
Route::get('/salud', SaludController::class)->middleware('throttle:30,1')->name('salud');

// Portal público de descarga de DTE: enlaces firmados, sin sesión ni vencimiento.
Route::middleware(['signed', 'throttle:60,1'])->controller(PortalDteController::class)->group(function () {
    Route::get('/dte/{codigo}', 'mostrar')->name('dte.portal');
    Route::get('/dte/{codigo}/json', 'json')->name('dte.portal.json');
    Route::get('/dte/{codigo}/pdf', 'pdf')->name('dte.portal.pdf');
});

// Buzón de DTE recibidos (correo entrante reenviado por el proveedor de correo).
Route::post('/buzon/{token}', BuzonEntradaController::class)->middleware('throttle:60,1')->name('buzon');

Route::middleware('auth')->group(function () {
    // Pantalla para activar el doble factor (accesible aunque aún no lo tenga).
    Route::view('/seguridad/2fa', 'auth.dos-factores')->name('seguridad.2fa');

    // 'empresa' va primero: fija el usuario en la sesión de BD para que RLS deje leer sus membresías.
    Route::middleware(['empresa', '2fa'])->group(function () {
        Route::get('/panel', Panel\Inicio::class)->name('panel');
        Route::get('/panel/emitir', Panel\Emitir::class)->name('panel.emitir');
        Route::get('/panel/caja', Panel\Caja::class)->name('panel.caja');
        Route::get('/panel/documentos', Panel\Documentos::class)->name('panel.documentos');
        Route::get('/panel/documentos/{dte}/pdf', [DocumentoDescargaController::class, 'pdf'])->name('panel.documentos.pdf');
        Route::get('/panel/documentos/{dte}/json', [DocumentoDescargaController::class, 'json'])->name('panel.documentos.json');
        Route::get('/panel/invalidaciones', Panel\Invalidaciones::class)->name('panel.invalidaciones');
        Route::get('/panel/documentos/{dte}/nota', Panel\NotaAjuste::class)->whereNumber('dte')->name('panel.nota');
        Route::get('/panel/contingencia', Panel\ContingenciaEstado::class)->name('panel.contingencia');
        Route::get('/panel/productos', Panel\Productos::class)->name('panel.productos');
        Route::get('/panel/clientes', Panel\Clientes::class)->name('panel.clientes');
        Route::get('/panel/establecimientos', Panel\Establecimientos::class)->name('panel.establecimientos');
        Route::get('/panel/recibidos', Panel\Recibidos::class)->name('panel.recibidos');
        Route::get('/panel/reportes', Panel\Reportes::class)->name('panel.reportes');
        Route::get('/panel/reportes/{libro}.{formato}', LibroDescargaController::class)
            ->whereIn('libro', ['consumidor', 'contribuyentes', 'compras'])->whereIn('formato', ['csv', 'xlsx'])->name('panel.reportes.descargar');
        Route::get('/panel/api', Panel\ApiWebhooks::class)->name('panel.api');
        Route::get('/panel/usuarios', Panel\Usuarios::class)->name('panel.usuarios');
        Route::get('/panel/plan', Panel\Suscripcion::class)->name('panel.suscripcion');
        Route::get('/panel/configuracion', Panel\Onboarding::class)->name('panel.onboarding');

        Route::post('/empresa-activa', EmpresaActivaController::class)->name('empresa-activa');

        // Superadmin de Escobar Net (2FA obligatorio).
        Route::middleware('can:superadmin')->prefix('admin')->name('admin.')->group(function () {
            Route::get('/', Admin\Empresas::class)->name('empresas');
            Route::get('/estado', Admin\Estado::class)->name('estado');
            Route::get('/planes', Admin\Planes::class)->name('planes');
            Route::get('/cobros', Admin\Cobros::class)->name('cobros');
            Route::post('/soporte/{empresa}', [SoporteController::class, 'entrar'])->whereNumber('empresa')->name('soporte.entrar');
        });
        Route::post('/soporte/salir', [SoporteController::class, 'salir'])->name('admin.soporte.salir');
    });
});
