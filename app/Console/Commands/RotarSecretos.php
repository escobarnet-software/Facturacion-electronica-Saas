<?php

namespace App\Console\Commands;

use App\Support\Seguridad\Secretos;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Vuelve a cifrar los secretos de los clientes con la llave ACTUAL (DTE_SECRETS_KEY).
 * Procedimiento completo en docs/operacion/runbook.md («Rotación de llaves»).
 */
class RotarSecretos extends Command
{
    /** Columnas cifradas con DTE_SECRETS_KEY. */
    public const COLUMNAS = [
        'empresa_credenciales' => ['pwd_api', 'pwd_privada'],
        'webhooks' => ['secreto'],
    ];

    protected $signature = 'dte:secretos:rotar {--verificar : solo cuenta los valores que aún usan una llave anterior}';

    protected $description = 'Vuelve a cifrar las credenciales de los clientes con la llave actual (rotación de DTE_SECRETS_KEY)';

    public function handle(Secretos $secretos, Tenancy $tenancy): int
    {
        // Tarea de plataforma sobre todas las empresas: sin aislamiento, y los valores nunca se imprimen.
        $pendientes = $tenancy->sinAislamiento(function () use ($secretos) {
            $pendientes = 0;
            foreach (self::COLUMNAS as $tabla => $columnas) {
                DB::table($tabla)->orderBy('id')->chunkById(200, function ($filas) use ($tabla, $columnas, $secretos, &$pendientes) {
                    foreach ($filas as $fila) {
                        $cambios = [];
                        foreach ($columnas as $columna) {
                            $valor = $fila->{$columna};
                            if ($valor === null || $secretos->usaLlaveActual((string) $valor)) {
                                continue;
                            }
                            $pendientes++;
                            if (! $this->option('verificar')) {
                                $cambios[$columna] = $secretos->cifrar($secretos->descifrar((string) $valor));
                            }
                        }
                        if ($cambios !== []) {
                            DB::table($tabla)->where('id', $fila->id)->update($cambios);
                        }
                    }
                });
            }

            return $pendientes;
        });

        if ($this->option('verificar')) {
            $this->line($pendientes === 0 ? 'Todos los secretos usan la llave actual: puede retirar las llaves anteriores.' : "{$pendientes} valor(es) aún usan una llave anterior.");

            return $pendientes === 0 ? self::SUCCESS : self::FAILURE;
        }
        $this->info("{$pendientes} valor(es) cifrados de nuevo con la llave actual.");

        return self::SUCCESS;
    }
}
