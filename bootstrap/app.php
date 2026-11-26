<?php

use App\Dte\Excepciones\DteException;
use App\Http\Middleware\CabecerasSeguridad;
use App\Http\Middleware\EmpresaDelToken;
use App\Http\Middleware\Exigir2FA;
use App\Http\Middleware\FijarEmpresaActiva;
use App\Http\Middleware\ProtegerFormularios;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'empresa' => FijarEmpresaActiva::class,
            '2fa' => Exigir2FA::class,
            'api.empresa' => EmpresaDelToken::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        // La empresa activa se fija ANTES de resolver los modelos de la ruta ({dte}):
        // así EmpresaScope y RLS ya filtran al buscarlos (otra empresa → 404).
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: FijarEmpresaActiva::class);

        // CSRF + origen sin la cookie XSRF-TOKEN (no la usa nadie y no es HttpOnly).
        $middleware->web(replace: [PreventRequestForgery::class => ProtegerFormularios::class]);

        // Cabeceras de seguridad en todas las respuestas (panel, portal, API).
        $middleware->append(CabecerasSeguridad::class);

        // El buzón lo llama el proveedor de correo entrante (sin sesión): se protege con su token secreto.
        $middleware->validateCsrfTokens(except: ['buzon/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Nunca devolver a la sesión (old input) los secretos de los clientes.
        $exceptions->dontFlash([
            'current_password', 'password', 'password_confirmation',
            'pwd_api', 'pwd_privada', 'passwordPri',
        ]);

        // API: mensajes en español y sin detalles internos.
        $exceptions->render(fn (AuthenticationException $e, Request $r) => $r->is('api/*') ? response()->json(['message' => 'Token inválido o ausente.'], 401) : null);
        $exceptions->render(fn (AccessDeniedHttpException $e, Request $r) => $r->is('api/*')
            ? response()->json(['message' => $e->getPrevious() instanceof MissingAbilityException ? 'El token no tiene permiso para esta operación.' : 'No tiene permiso para esta operación.'], 403)
            : null);
        $exceptions->render(fn (DteException $e, Request $r) => $r->is('api/*') ? response()->json(['message' => $e->getMessage()], 422) : null);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
