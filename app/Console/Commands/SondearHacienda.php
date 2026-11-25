<?php

namespace App\Console\Commands;

use App\Models\EmpresaCredencial;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Healthcheck ACTIVO de Hacienda (cada minuto): una petición liviana a la URL base de cada ambiente en uso.
 * Solo comprueba que el servicio responda (cualquier respuesta HTTP); la métrica queda en hacienda_metricas.
 * Desactivado por defecto (DTE_SONDEO_HACIENDA=false) hasta habilitar la conexión con Hacienda.
 */
class SondearHacienda extends Command
{
    protected $signature = 'dte:hacienda:sondear {--forzar : sondear aunque esté desactivado}';

    protected $description = 'Comprueba cada minuto que los servicios de Hacienda respondan (healthcheck)';

    public function handle(Tenancy $tenancy): int
    {
        if (! config('dte.salud.sondeo_hacienda') && ! $this->option('forzar')) {
            $this->line('Sondeo de Hacienda desactivado (DTE_SONDEO_HACIENDA=false).');

            return self::SUCCESS;
        }
        // Solo qué ambientes se usan (sin leer credenciales): tarea de plataforma.
        $ambientes = $tenancy->sinAislamiento(fn () => EmpresaCredencial::query()->distinct()->pluck('ambiente')->all());
        foreach ($ambientes as $ambiente) {
            $base = (string) config("dte.api.base_url.{$ambiente}");
            try {
                $r = Http::timeout((int) config('dte.api.timeout_segundos'))->withUserAgent((string) config('dte.api.user_agent'))->get($base.'/');
                $this->line("{$ambiente}: HTTP {$r->status()}");
            } catch (Throwable) {
                $this->warn("{$ambiente}: sin respuesta");
            }
        }

        return self::SUCCESS;
    }
}
