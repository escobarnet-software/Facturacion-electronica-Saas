<?php

namespace App\Jobs\Middleware;

use App\Tenancy\Tenancy;
use Closure;

/**
 * Middleware para jobs en cola: fija la empresa recibida EXPLÍCITAMENTE en el
 * job antes de trabajar (EmpresaScope + RLS) y, al terminar, deja la empresa
 * como estaba: en un worker, ninguna (el siguiente job no hereda la empresa);
 * en la cola síncrona (dentro de una petición), la de la petición.
 *
 * Uso en el job:  public function middleware(): array { return [new ConEmpresa($this->empresaId)]; }
 */
final class ConEmpresa
{
    public function __construct(public readonly int $empresaId) {}

    public function handle(object $job, Closure $next): mixed
    {
        $tenancy = app(Tenancy::class);
        $anterior = $tenancy->empresaId();
        $tenancy->establecer($this->empresaId);

        try {
            return $next($job);
        } finally {
            $anterior === null ? $tenancy->limpiar() : $tenancy->establecer($anterior);
        }
    }
}
