<?php

namespace App\Jobs\Middleware;

use App\Tenancy\Tenancy;
use Closure;

/**
 * Middleware para jobs en cola: fija la empresa recibida EXPLÍCITAMENTE en el
 * job antes de trabajar (EmpresaScope + RLS) y la limpia al terminar, para que
 * el siguiente job del mismo worker no herede la empresa.
 *
 * Uso en el job:  public function middleware(): array { return [new ConEmpresa($this->empresaId)]; }
 */
final class ConEmpresa
{
    public function __construct(public readonly int $empresaId) {}

    public function handle(object $job, Closure $next): mixed
    {
        $tenancy = app(Tenancy::class);
        $tenancy->establecer($this->empresaId);

        try {
            return $next($job);
        } finally {
            $tenancy->limpiar();
        }
    }
}
