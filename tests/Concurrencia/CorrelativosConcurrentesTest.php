<?php

use App\Models\Empresa;
use App\Models\Usuario;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/*
| Terminado cuando (Paso 3): 50 emisiones concurrentes no producen números repetidos.
|
| Son 50 procesos PHP independientes, cada uno con su propia conexión a PostgreSQL,
| pidiendo el siguiente número al mismo tiempo. Sin la transacción de la prueba
| (los datos se confirman de verdad), por eso se limpian al final.
*/

it('50 emisiones concurrentes no producen números repetidos', function () {
    Artisan::call('migrate', ['--force' => true]);
    $d = crearEmpresaDePrueba();

    try {
        $resultados = Process::pool(function (Pool $pool) use ($d) {
            for ($i = 0; $i < 50; $i++) {
                $pool->path(base_path())
                    ->env(['DB_DATABASE' => config('database.connections.pgsql.database'), 'DTE_SECRETS_KEY' => config('dte.secrets_key')])
                    ->timeout(120)
                    ->command([PHP_BINARY, 'tests/Concurrencia/obtener-correlativo.php', $d['empresa']->id, $d['establecimiento']->id, $d['puntoVenta']->id]);
            }
        })->start()->wait();

        $numeros = [];
        foreach ($resultados as $r) {
            expect($r->successful())->toBeTrue($r->errorOutput());
            $numeros[] = trim($r->output());
        }

        sort($numeros);
        $esperados = array_map(fn ($n) => sprintf('DTE-01-M001P001-%015d', $n), range(1, 50));

        expect($numeros)->toHaveCount(50)
            ->and(array_unique($numeros))->toHaveCount(50)
            ->and($numeros)->toBe($esperados);
    } finally {
        // Limpieza (la empresa de prueba no tiene bitácora; el resto se borra en cascada).
        tenancy()->sinAislamiento(function () use ($d) {
            Empresa::query()->whereKey($d['empresa']->id)->delete();
            Usuario::query()->whereKey($d['usuario']->id)->delete();
        });
    }
});
