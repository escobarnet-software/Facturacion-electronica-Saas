<?php

namespace App\Console\Commands;

use App\Plataforma\Salud\Vigilante;
use Illuminate\Console\Command;

class VigilarPlataforma extends Command
{
    protected $signature = 'plataforma:vigilar';

    protected $description = 'Revisa la salud de la plataforma y avisa a los superadmin';

    public function handle(Vigilante $vigilante): int
    {
        $enviadas = $vigilante->vigilar();
        $this->line($enviadas === [] ? 'Sin alertas nuevas.' : 'Alertas: '.implode(', ', $enviadas));

        return self::SUCCESS;
    }
}
