<?php

namespace App\Jobs;

use App\Dte\Pruebas\EjecutorPruebasMinimas;
use App\Jobs\Middleware\ConEmpresa;
use App\Models\Empresa;
use App\Models\EmpresaCredencial;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Lanza las pruebas mínimas desde el asistente de onboarding (el avance queda en pruebas_minimas). */
class EjecutarPruebasMinimas implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    /** @param list<string> $tipos */
    public function __construct(public readonly int $empresaId, public readonly array $tipos)
    {
        $this->onQueue('default');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ConEmpresa($this->empresaId)];
    }

    public function handle(EjecutorPruebasMinimas $ejecutor): void
    {
        $ejecutor->ejecutar(
            Empresa::query()->findOrFail($this->empresaId),
            EmpresaCredencial::query()->where('ambiente', '00')->firstOrFail(),
            $this->tipos,
        );
    }
}
