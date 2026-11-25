<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad para todas las respuestas (OWASP ASVS V14.4).
 *
 *  - CSP con nonce por petición para los scripts (Vite y Livewire lo usan solos).
 *    'unsafe-eval' sigue siendo necesario para Alpine/Livewire en modo normal
 *    (TODO(seguridad): evaluar livewire.csp_safe para retirarlo).
 *  - HSTS solo sobre HTTPS. Sin incrustar en marcos (clickjacking).
 */
final class CabecerasSeguridad
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Str::random(32);
        Vite::useCspNonce($nonce);

        $respuesta = $next($request);
        $h = $respuesta->headers;

        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'DENY');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $h->set('Cross-Origin-Opener-Policy', 'same-origin');
        $h->remove('X-Powered-By');
        if ($request->isSecure()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Horizon trae su propio JavaScript en línea (solo superadmin) y el servidor de Vite en desarrollo: sin CSP.
        if (! $request->is('horizon', 'horizon/*') && ! Vite::isRunningHot() && ! $h->has('Content-Security-Policy')) {
            $h->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data:",
                "font-src 'self'",
                "connect-src 'self'",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
            ]));
        }

        return $respuesta;
    }
}
