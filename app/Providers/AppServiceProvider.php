<?php

namespace App\Providers;

use App\Support\Seguridad\Secretos;
use App\Tenancy\Tenancy;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
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
        // Red de seguridad: ningún job deja su empresa fijada al siguiente.
        $limpiar = fn () => app(Tenancy::class)->limpiar();
        Event::listen(JobProcessed::class, $limpiar);
        Event::listen(JobFailed::class, $limpiar);
    }
}
