<?php

use App\Http\Middleware\Exigir2FA;
use App\Http\Middleware\FijarEmpresaActiva;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'empresa' => FijarEmpresaActiva::class,
            '2fa' => Exigir2FA::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Nunca devolver a la sesión (old input) los secretos de los clientes.
        $exceptions->dontFlash([
            'current_password', 'password', 'password_confirmation',
            'pwd_api', 'pwd_privada', 'passwordPri',
        ]);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
