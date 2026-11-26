<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/**
 * Misma respuesta exista o no el correo: así no se puede averiguar qué correos tienen cuenta
 * (enumeración de usuarios). La espera entre envíos la controla el broker de contraseñas.
 */
final class EnlaceRestablecerEnviado implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public function toResponse($request): RedirectResponse
    {
        return back()->with('aviso', 'Si el correo está registrado, le enviamos un enlace para crear una nueva contraseña. Revise su bandeja de entrada y la de correo no deseado.');
    }
}
