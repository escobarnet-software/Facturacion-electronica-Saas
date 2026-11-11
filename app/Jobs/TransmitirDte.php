<?php

namespace App\Jobs;

use App\Dte\Hacienda\TransmisorDte;
use App\Jobs\Middleware\ConEmpresa;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\EmpresaCredencial;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Firma (si hace falta) y transmite un DTE a Hacienda, en la cola «transmision».
 * Recibe el empresa_id EXPLÍCITO y lo fija antes de trabajar (EmpresaScope + RLS).
 * Los reintentos los maneja TransmisorDte según el MTIST, por eso el job se intenta una sola vez.
 */
class TransmitirDte implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $empresaId, public readonly int $dteId)
    {
        $this->onQueue('transmision');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ConEmpresa($this->empresaId)];
    }

    public function handle(): void
    {
        $dte = Dte::query()->findOrFail($this->dteId);
        $empresa = Empresa::query()->findOrFail($this->empresaId);
        $credencial = EmpresaCredencial::query()->where('ambiente', $dte->ambiente)->firstOrFail();

        TransmisorDte::crear()->procesar($dte, $empresa, $credencial);
    }
}
