<?php

namespace App\Actions\Fortify;

use App\Models\Usuario;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

/** Restablece la contraseña desde el enlace del correo («¿Olvidó su contraseña?»). */
final class RestablecerContrasena implements ResetsUserPasswords
{
    /**
     * @param  Usuario  $user
     * @param  array<string, mixed>  $input
     */
    public function reset($user, array $input): void
    {
        Validator::make($input, [
            'password' => ['required', 'string', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ], [], ['password' => 'contraseña'])->validate();

        $user->forceFill([
            'password' => $input['password'],
            // Restablecer desde su correo prueba que el correo es suyo (también activa una invitación pendiente).
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();
    }
}
