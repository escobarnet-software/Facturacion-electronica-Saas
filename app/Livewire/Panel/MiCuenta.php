<?php

declare(strict_types=1);

namespace App\Livewire\Panel;

use App\Models\Bitacora;
use App\Models\Usuario;
use App\Tenancy\Tenancy;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Mi cuenta (todos los usuarios): cambiar la contraseña y ver los códigos de recuperación del doble factor. */
#[Layout('layouts.panel')]
#[Title('Mi cuenta')]
class MiCuenta extends Component
{
    public string $actual = '';

    public string $nueva = '';

    public string $nueva_confirmation = '';

    public string $claveCodigos = '';

    /** @var list<string> Códigos visibles solo en esta respuesta; nunca se guardan en el estado del componente entre visitas. */
    public array $codigos = [];

    public ?string $mensaje = null;

    public function cambiarContrasena(): void
    {
        $usuario = $this->usuario();
        $this->validate([
            'actual' => ['required', 'string'],
            'nueva' => ['required', 'string', 'confirmed', 'different:actual', Password::min(12)->mixedCase()->numbers()],
        ], [
            'nueva.different' => 'La nueva contraseña debe ser distinta de la actual.',
        ], ['actual' => 'contraseña actual', 'nueva' => 'nueva contraseña']);

        if (! $this->claveCorrecta($usuario, $this->actual, 'actual')) {
            return;
        }

        $usuario->forceFill(['password' => Hash::make($this->nueva)])->save();
        // Cierra las sesiones abiertas en otros equipos con la contraseña anterior.
        Auth::logoutOtherDevices($this->nueva);
        $this->anotar('usuario.contrasena', $usuario);
        $this->reset('actual', 'nueva', 'nueva_confirmation');
        $this->mensaje = 'Contraseña cambiada. Las sesiones abiertas en otros equipos se cerraron.';
    }

    public function verCodigos(): void
    {
        $usuario = $this->usuario();
        $this->validate(['claveCodigos' => ['required', 'string']], [], ['claveCodigos' => 'contraseña']);
        if ($this->claveCorrecta($usuario, $this->claveCodigos, 'claveCodigos')) {
            $this->codigos = $usuario->tiene2fa() ? array_values($usuario->recoveryCodes()) : [];
        }
        $this->reset('claveCodigos');
    }

    public function generarCodigos(GenerateNewRecoveryCodes $generar): void
    {
        $usuario = $this->usuario();
        $this->validate(['claveCodigos' => ['required', 'string']], [], ['claveCodigos' => 'contraseña']);
        if ($this->claveCorrecta($usuario, $this->claveCodigos, 'claveCodigos') && $usuario->tiene2fa()) {
            $generar($usuario);
            $this->anotar('usuario.2fa.codigos', $usuario);
            $this->codigos = array_values($usuario->fresh()?->recoveryCodes() ?? []);
            $this->mensaje = 'Se generaron códigos nuevos. Los anteriores ya no sirven.';
        }
        $this->reset('claveCodigos');
    }

    public function render(): View
    {
        return view('livewire.panel.mi-cuenta', ['usuario' => $this->usuario()]);
    }

    /** La bitácora es por empresa: el superadmin sin empresa activa no tiene bitácora de empresa. */
    private function anotar(string $accion, Usuario $usuario): void
    {
        if (app(Tenancy::class)->hayEmpresa()) {
            Bitacora::registrar($accion, $usuario);
        }
    }

    private function usuario(): Usuario
    {
        /** @var Usuario $u */
        $u = Auth::user();

        return $u;
    }

    /** Verifica la contraseña con límite de intentos (5 por minuto por usuario). */
    private function claveCorrecta(Usuario $usuario, #[\SensitiveParameter] string $clave, string $campo): bool
    {
        $llave = 'mi-cuenta:'.$usuario->id;
        if (RateLimiter::tooManyAttempts($llave, 5)) {
            $this->addError($campo, 'Demasiados intentos. Espere un minuto.');

            return false;
        }
        if (! Hash::check($clave, (string) $usuario->password)) {
            RateLimiter::hit($llave, 60);
            $this->addError($campo, 'La contraseña no es correcta.');

            return false;
        }
        RateLimiter::clear($llave);

        return true;
    }
}
