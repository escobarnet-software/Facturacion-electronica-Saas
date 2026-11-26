<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/**
 * Aceptar la invitación: enlace firmado (72 h). Es de un solo uso: al crear la contraseña se marca
 * el correo como verificado y el mismo enlace ya no sirve.
 */
final class InvitacionController
{
    public function mostrar(Usuario $usuario): View
    {
        abort_if($usuario->email_verified_at !== null, 410, 'Esta invitación ya fue usada. Inicie sesión con su correo y contraseña.');

        return view('auth.invitacion', ['usuario' => $usuario]);
    }

    public function aceptar(Request $request, Usuario $usuario): RedirectResponse
    {
        abort_if($usuario->email_verified_at !== null, 410, 'Esta invitación ya fue usada. Inicie sesión con su correo y contraseña.');
        $datos = $request->validate([
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ], [], ['password' => 'contraseña']);

        $usuario->forceFill(['password' => $datos['password'], 'email_verified_at' => now()])->save();
        Auth::login($usuario);
        $request->session()->regenerate();

        // El dueño es llevado a activar el doble factor por el middleware 2fa; los demás entran directo.
        return redirect()->route('panel');
    }
}
