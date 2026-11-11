<?php

namespace App\Providers;

use App\Enums\Rol;
use App\Models\Usuario;
use App\Support\Seguridad\Secretos;
use App\Tenancy\Tenancy;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Tenancy::class);
        $this->app->singleton(Secretos::class);
    }

    public function boot(): void
    {
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
        Gate::define('ver-contingencia', fn (Usuario $u) => in_array($rol($u), [Rol::Dueno, Rol::Contador], true));
    }
}
