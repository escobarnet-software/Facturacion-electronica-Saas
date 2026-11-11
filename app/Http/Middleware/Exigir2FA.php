<?php

namespace App\Http\Middleware;

use App\Models\Usuario;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El doble factor es obligatorio para dueños y superadmin: mientras no lo
 * confirmen, solo pueden entrar a la pantalla para activarlo.
 */
final class Exigir2FA
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Usuario|null $usuario */
        $usuario = $request->user();

        if ($usuario !== null && $usuario->exige2fa() && ! $usuario->tiene2fa()) {
            if ($request->expectsJson()) {
                abort(403, 'Debe activar la verificación en dos pasos para continuar.');
            }

            return redirect()->route('seguridad.2fa')
                ->with('aviso', 'Por seguridad, active la verificación en dos pasos para continuar.');
        }

        return $next($request);
    }
}
