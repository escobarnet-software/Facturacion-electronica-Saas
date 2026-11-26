<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Usuario;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;

/**
 * Reinicia la verificación en dos pasos de un usuario que perdió su celular y sus códigos de recuperación.
 * Lo ejecuta Escobar Net en el servidor después de confirmar la identidad de la persona por un medio seguro.
 */
class ReiniciarDobleFactor extends Command
{
    protected $signature = 'plataforma:2fa:reiniciar {email : correo del usuario}';

    protected $description = 'Quita la verificación en dos pasos de un usuario para que la active de nuevo al entrar';

    public function handle(Tenancy $tenancy): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        // La tabla de usuarios es global (no es de una empresa): se busca sin aislamiento a propósito.
        $usuario = $tenancy->sinAislamiento(fn () => Usuario::query()->where('email', $email)->first());
        if (! $usuario instanceof Usuario) {
            $this->error("No existe un usuario con el correo {$email}.");

            return self::FAILURE;
        }

        $usuario->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $this->info("Listo. {$email} deberá activar de nuevo la verificación en dos pasos al entrar (escaneando el código QR).");

        return self::SUCCESS;
    }
}
