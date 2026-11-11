<?php

use App\Http\Middleware\Exigir2FA;
use App\Http\Middleware\FijarEmpresaActiva;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

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

        // La empresa activa se fija ANTES de resolver los modelos de la ruta ({dte}):
        // así EmpresaScope y RLS ya filtran al buscarlos (otra empresa → 404).
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: FijarEmpresaActiva::class);

        // El buzón lo llama el proveedor de correo entrante (sin sesión): se protege con su token secreto.
        $middleware->validateCsrfTokens(except: ['buzon/*']);
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
