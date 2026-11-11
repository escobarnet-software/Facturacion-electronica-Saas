<?php

namespace App\Jobs;

use App\Dte\Representacion\Entregador;
use App\Jobs\Middleware\ConEmpresa;
use App\Models\Dte;
use App\Models\Empresa;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Archiva y entrega un DTE al receptor, en la cola «entrega». */
class EntregarDte implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public readonly int $empresaId, public readonly int $dteId)
    {
        $this->onQueue('entrega');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ConEmpresa($this->empresaId)];
    }

    public function handle(Entregador $entregador): void
    {
        $entregador->entregar(Dte::query()->findOrFail($this->dteId), Empresa::query()->findOrFail($this->empresaId));
    }
}
