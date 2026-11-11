<?php

/*
 * Proceso hijo de la prueba de concurrencia: arranca Laravel, fija la empresa
 * y pide UN número de control. Uso: php obtener-correlativo.php {empresa} {est} {pv}
 */

use App\Dte\Correlativos\GeneradorNumeroControl;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Tenancy\Tenancy;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$empresaId, $establecimientoId, $puntoVentaId] = array_map('intval', array_slice($argv, 1, 3));

$app->make(Tenancy::class)->establecer($empresaId);
$resultado = $app->make(GeneradorNumeroControl::class)->siguiente(
    Empresa::query()->findOrFail($empresaId),
    '01',
    Establecimiento::query()->findOrFail($establecimientoId),
    PuntoVenta::query()->findOrFail($puntoVentaId),
    now(),
);

echo $resultado['numero_control'];
