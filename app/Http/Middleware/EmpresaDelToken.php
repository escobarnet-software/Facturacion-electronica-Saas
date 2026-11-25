<?php

namespace App\Http\Middleware;

use App\Models\Empresa;
use App\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** API: la empresa activa es la dueña del token (nunca un parámetro de la petición). */
final class EmpresaDelToken
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $empresa = $request->user();
        abort_unless($empresa instanceof Empresa, 401, 'Token inválido o ausente.');

        $this->tenancy->establecer($empresa->id);
        if ($empresa->estado === 'suspendida') {
            $this->tenancy->limpiar();

            return response()->json(['message' => 'La cuenta de la empresa está suspendida. Comuníquese con Escobar Net.'], 403);
        }
        try {
            return $next($request);
        } finally {
            $this->tenancy->limpiar();
        }
    }
}
