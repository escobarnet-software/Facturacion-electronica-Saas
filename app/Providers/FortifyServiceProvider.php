<?php

namespace App\Providers;

use App\Actions\Fortify\RestablecerContrasena;
use App\Http\Responses\EnlaceRestablecerEnviado;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Misma respuesta exista o no el correo (evita enumerar usuarios).
        $this->app->singleton(FailedPasswordResetLinkRequestResponse::class, EnlaceRestablecerEnviado::class);
        $this->app->singleton(SuccessfulPasswordResetLinkRequestResponse::class, EnlaceRestablecerEnviado::class);
    }

    public function boot(): void
    {
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.olvide-contrasena'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.restablecer-contrasena', ['request' => $request]));
        Fortify::resetUserPasswordsUsing(RestablecerContrasena::class);

        // Correo de restablecimiento en español.
        ResetPassword::toMailUsing(fn (object $usuario, string $token) => (new MailMessage)
            ->subject('Restablecer su contraseña de Escobar Net DTE')
            ->greeting('Hola:')
            ->line('Recibimos una solicitud para restablecer la contraseña de su cuenta.')
            ->action('Crear una nueva contraseña', url(route('password.reset', ['token' => $token, 'email' => $usuario->getEmailForPasswordReset()], false)))
            ->line('El enlace vence en '.config('auth.passwords.users.expire').' minutos y sirve una sola vez.')
            ->line('Si usted no lo pidió, ignore este correo: su contraseña no cambia.')
            ->salutation('Escobar Net'));

        RateLimiter::for('login', function (Request $request) {
            $llave = Str::transliterate(Str::lower($request->string(Fortify::username())->toString()).'|'.$request->ip());

            return Limit::perMinute(5)->by($llave);
        });

        RateLimiter::for('acceso', fn (Request $request) => Limit::perMinute(30)->by((string) $request->ip()));

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by((string) $request->session()->get('login.id'));
        });
    }
}
