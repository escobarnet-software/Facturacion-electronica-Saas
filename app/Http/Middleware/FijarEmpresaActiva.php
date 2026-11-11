<?php

namespace App\Http\Middleware;

use App\Models\Usuario;
use App\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija la empresa activa del usuario autenticado (EmpresaScope + RLS) para
 * toda la petición y la limpia al terminar.
 *
 * En APP_MODE=dedicado, si el usuario pertenece a una sola empresa, esa es la activa.
 */
final class FijarEmpresaActiva
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Usuario|null $usuario */
        $usuario = $request->user();

        if ($usuario === null) {
            return $next($request);
        }

        $this->tenancy->fijarUsuario($usuario->id);

        $empresaId = $usuario->empresa_activa_id;
        if ($empresaId === null || ! $usuario->perteneceA($empresaId)) {
            $empresaId = $this->primeraEmpresa($usuario);
            if ($empresaId !== null && $usuario->empresa_activa_id !== $empresaId) {
                $usuario->forceFill(['empresa_activa_id' => $empresaId])->save();
            }
        }

        if ($empresaId !== null) {
            $this->tenancy->establecer($empresaId);
        }

        try {
            return $next($request);
        } finally {
            $this->tenancy->limpiar();
        }
    }

    private function primeraEmpresa(Usuario $usuario): ?int
    {
        $id = $usuario->empresas()->orderBy('empresas.id')->value('empresas.id');

        return $id === null ? null : (int) $id;
    }
}
