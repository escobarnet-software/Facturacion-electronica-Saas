<?php

namespace App\Http\Middleware;

use App\Models\Empresa;
use App\Models\Usuario;
use App\Plataforma\Soporte;
use App\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
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

        $soporte = app(Soporte::class)->empresaDeSesion($usuario);
        $empresaId = $soporte ?? $usuario->empresa_activa_id;
        if ($soporte === null && ($empresaId === null || ! $usuario->perteneceA($empresaId))) {
            $empresaId = $this->primeraEmpresa($usuario);
            if ($empresaId !== null && $usuario->empresa_activa_id !== $empresaId) {
                $usuario->forceFill(['empresa_activa_id' => $empresaId])->save();
            }
        }

        if ($empresaId !== null) {
            $this->tenancy->establecer($empresaId);
            // Empresa suspendida por Escobar Net: sin acceso al panel (el soporte sí puede consultar).
            if ($soporte === null && Empresa::query()->whereKey($empresaId)->value('estado') === 'suspendida') {
                $this->tenancy->limpiar();
                abort(403, 'La cuenta de su empresa está suspendida. Comuníquese con Escobar Net.');
            }
        }

        // En /livewire/update este middleware corre como «persistente» en un pipeline aparte, ANTES de la acción
        // del componente: la empresa debe seguir fijada durante la acción y se limpia al terminar la petición.
        if (app(HandleRequests::class)->isLivewireRequest()) {
            app()->terminating(fn () => $this->tenancy->limpiar());

            return $next($request);
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
