<?php

namespace App\Providers;

use App\Enums\Rol;
use App\Http\Middleware\Exigir2FA;
use App\Http\Middleware\FijarEmpresaActiva;
use App\Models\TokenApi;
use App\Models\Usuario;
use App\Plataforma\MetricasHacienda;
use App\Support\Seguridad\Secretos;
use App\Tenancy\Tenancy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Tenancy::class);
        $this->app->singleton(Secretos::class);
    }

    public function boot(): void
    {
        // Modo demo: Hacienda (pruebas) y firmador simulados en local. Nunca en producción.
        $simulador = config('dte.simulador_url');
        if (is_string($simulador) && $simulador !== '' && ! $this->app->environment('production')
            && preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', $simulador)) {
            config(['dte.api.base_url.00' => $simulador, 'dte.firmador.url' => $simulador.'/firmardocumento/']);
        }

        // Las peticiones de actualización de Livewire (/livewire/update) deben volver a fijar la empresa activa
        // (EmpresaScope + RLS) y exigir el 2FA, igual que la ruta donde vive el componente.
        Livewire::addPersistentMiddleware([FijarEmpresaActiva::class, Exigir2FA::class]);

        // Métricas de Hacienda (latencia y errores por endpoint) para el superadmin.
        Event::listen(ResponseReceived::class, [MetricasHacienda::class, 'respuesta']);
        Event::listen(ConnectionFailed::class, [MetricasHacienda::class, 'falla']);
        Gate::define('superadmin', fn (Usuario $u) => $u->es_superadmin);

        // API: tokens de empresa con permisos; límite de peticiones por token.
        Sanctum::usePersonalAccessTokenModel(TokenApi::class);
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by('token:'.($r->bearerToken() !== null ? hash('sha256', $r->bearerToken()) : $r->ip()))
            ->response(fn () => response()->json(['message' => 'Demasiadas solicitudes: espere un momento e intente de nuevo.'], 429)));

        // Red de seguridad: en un worker, ningún job deja su empresa fijada al siguiente.
        // (En la cola síncrona el job corre dentro de la petición: ConEmpresa restaura la empresa.)
        $limpiar = function (JobProcessed|JobFailed $evento): void {
            if ($evento->connectionName !== 'sync') {
                app(Tenancy::class)->limpiar();
            }
        };
        Event::listen(JobProcessed::class, $limpiar);
        Event::listen(JobFailed::class, $limpiar);

        // Permisos por rol dentro de la empresa activa (ESPECIFICACION §1.3).
        $rol = fn (Usuario $u) => $u->rolActual();
        Gate::define('emitir', fn (Usuario $u) => in_array($rol($u), [Rol::Dueno, Rol::Contador, Rol::Cajero], true));
        Gate::define('ver-documentos', fn (Usuario $u) => $rol($u) !== null);
        Gate::define('invalidar', fn (Usuario $u) => in_array($rol($u), [Rol::Dueno, Rol::Contador], true));
        Gate::define('administrar-catalogos', fn (Usuario $u) => in_array($rol($u), [Rol::Dueno, Rol::Contador], true));
        Gate::define('administrar-empresa', fn (Usuario $u) => $rol($u) === Rol::Dueno);
        Gate::define('ver-libros', fn (Usuario $u) => in_array($rol($u), [Rol::Dueno, Rol::Contador], true));
        Gate::define('ver-contingencia', fn (Usuario $u) => in_array($rol($u), [Rol::Dueno, Rol::Contador], true));
    }
}
