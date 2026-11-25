<?php

namespace App\Console\Commands;

use App\Plataforma\FacturadorSuscripciones;
use Illuminate\Console\Command;

class FacturarSuscripciones extends Command
{
    protected $signature = 'plataforma:facturar {periodo? : AAAA-MM (por defecto, el mes anterior)} {--sin-dte : solo calcula los cobros}';

    protected $description = 'Calcula los cobros mensuales (plan + excedente) y emite los DTE de cobro de Escobar Net';

    public function handle(FacturadorSuscripciones $facturador): int
    {
        $periodo = (string) ($this->argument('periodo') ?? now((string) config('dte.zona_horaria'))->subMonthNoOverflow()->format('Y-m'));
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) {
            $this->error('El período debe tener la forma AAAA-MM.');

            return self::INVALID;
        }
        if (config('plataforma.empresa_id') === null && ! $this->option('sin-dte')) {
            $this->warn('PLATAFORMA_EMPRESA_ID no está configurado: se calculan los cobros sin emitir los DTE.');
        }
        $cobros = $facturador->facturar($periodo, ! $this->option('sin-dte'));
        $this->info(count($cobros).' cobro(s) del período '.$periodo.'.');

        return self::SUCCESS;
    }
}
