<?php

namespace App\Console\Commands;

use App\Dte\Eventos\GestorContingencia;
use App\Models\Contingencia;
use App\Models\Empresa;
use App\Models\EmpresaCredencial;
use App\Tenancy\Tenancy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('dte:contingencia:procesar')]
#[Description('Cada 15 minutos: prueba Hacienda, envía eventos de contingencia y lotes pendientes, y consulta lotes.')]
class ProcesarContingencias extends Command
{
    public function handle(Tenancy $tenancy, GestorContingencia $gestor): int
    {
        // Sin aislamiento SOLO para listar qué empresas tienen contingencias abiertas (tarea de plataforma).
        // El trabajo de cada empresa se hace después con su empresa fijada (EmpresaScope + RLS).
        $pendientes = $tenancy->sinAislamiento(fn () => Contingencia::query()
            ->where('estado', '!=', Contingencia::COMPLETADA)
            ->select(['empresa_id', 'ambiente'])->distinct()->get()
            ->map(fn (Contingencia $c) => [$c->empresa_id, $c->ambiente])->all());

        foreach ($pendientes as [$empresaId, $ambiente]) {
            $tenancy->ejecutarComo($empresaId, function () use ($empresaId, $ambiente, $gestor) {
                $empresa = Empresa::query()->findOrFail($empresaId);
                $credencial = EmpresaCredencial::query()->where('ambiente', $ambiente)->first();
                if ($credencial === null) {
                    $this->warn("Empresa {$empresaId}: sin credenciales para el ambiente {$ambiente}.");

                    return;
                }

                try {
                    $gestor->procesar($empresa, $credencial);
                } catch (Throwable $e) {
                    // Un error de una empresa no detiene a las demás. El mensaje no lleva secretos.
                    report($e);
                    $this->error("Empresa {$empresaId}: {$e->getMessage()}");
                }
            });
        }

        $this->info('Contingencias procesadas: '.count($pendientes));

        return self::SUCCESS;
    }
}
