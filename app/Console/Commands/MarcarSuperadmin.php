<?php

namespace App\Console\Commands;

use App\Models\Usuario;
use Illuminate\Console\Command;

class MarcarSuperadmin extends Command
{
    protected $signature = 'plataforma:superadmin {email : correo de un usuario existente} {--quitar}';

    protected $description = 'Da (o quita) el rol de superadmin de Escobar Net a un usuario existente';

    public function handle(): int
    {
        $usuario = Usuario::query()->where('email', (string) $this->argument('email'))->first();
        if ($usuario === null) {
            $this->error('No existe un usuario con ese correo.');

            return self::FAILURE;
        }
        $usuario->forceFill(['es_superadmin' => ! $this->option('quitar')])->save();
        $this->info($this->option('quitar') ? 'Se quitó el rol de superadmin.' : 'El usuario ahora es superadmin (deberá activar el doble factor).');

        return self::SUCCESS;
    }
}
