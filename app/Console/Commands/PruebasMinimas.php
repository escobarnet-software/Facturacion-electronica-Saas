<?php

namespace App\Console\Commands;

use App\Dte\Pruebas\EjecutorPruebasMinimas;
use App\Models\Empresa;
use App\Models\EmpresaCredencial;
use App\Tenancy\Tenancy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;

#[Signature('dte:pruebas-minimas {empresa : ID o NIT de la empresa} {--tipos= : Lista separada por comas (01,03,invalidacion…); por defecto todos} {--dry-run : Solo arma y valida, sin transmitir ni guardar}')]
#[Description('Ejecuta las pruebas mínimas de Hacienda (Manual Funcional XXIV) en el ambiente de pruebas, con avance y reanudación.')]
class PruebasMinimas extends Command
{
    public function handle(Tenancy $tenancy, EjecutorPruebasMinimas $ejecutor): int
    {
        $clave = (string) $this->argument('empresa');
        // Sin aislamiento SOLO para encontrar la empresa por ID o NIT (comando de plataforma).
        $empresaId = $tenancy->sinAislamiento(fn () => Empresa::query()->where('id', ctype_digit($clave) && strlen($clave) < 9 ? (int) $clave : 0)->orWhere('nit', $clave)->value('id'));
        if ($empresaId === null) {
            $this->error("No existe la empresa «{$clave}».");

            return self::FAILURE;
        }

        $tipos = $this->option('tipos') ? array_map('trim', explode(',', (string) $this->option('tipos'))) : array_keys(EjecutorPruebasMinimas::requeridas());
        $desconocidos = array_diff($tipos, array_keys(EjecutorPruebasMinimas::requeridas()));
        if ($desconocidos !== []) {
            $this->error('Tipos desconocidos: '.implode(', ', $desconocidos));

            return self::FAILURE;
        }

        return $tenancy->ejecutarComo((int) $empresaId, function () use ($empresaId, $tipos, $ejecutor) {
            $empresa = Empresa::query()->findOrFail($empresaId);
            $barras = [];
            $avance = function (string $tipo, int $hechas, int $total) use (&$barras) {
                $barras[$tipo] ??= tap(new ProgressBar($this->output, $total), fn (ProgressBar $b) => $b->setFormat("  {$tipo} %current%/%max% [%bar%] %percent:3s%%"));
                $barras[$tipo]->setProgress($hechas);
                if ($hechas >= $total) {
                    $barras[$tipo]->finish();
                    $this->newLine();
                }
            };

            if ($this->option('dry-run')) {
                $this->info("Simulación para {$empresa->razon_social} (no se transmite nada):");
                $resultado = $ejecutor->simular($empresa, $tipos, $avance);
                $this->table(['Tipo', 'Generados', 'Válidos'], array_map(fn ($t, $r) => [$t, $r['generados'], $r['validos']], array_keys($resultado), $resultado));
                $invalidos = array_filter($resultado, fn ($r) => $r['validos'] !== $r['generados']);
                foreach ($invalidos as $tipo => $r) {
                    $this->error("{$tipo}: ".($r['errores'][0] ?? ''));
                }

                return $invalidos === [] ? self::SUCCESS : self::FAILURE;
            }

            $credencial = EmpresaCredencial::query()->where('ambiente', '00')->first();
            if ($credencial === null) {
                $this->error('La empresa no tiene credenciales del ambiente de pruebas (00).');

                return self::FAILURE;
            }

            $this->info("Pruebas mínimas para {$empresa->razon_social} (ambiente de pruebas). Se reanuda donde quedó.");
            $filas = $ejecutor->ejecutar($empresa, $credencial, $tipos, $avance);
            $this->table(['Tipo', 'Exitosas', 'Requeridas', 'Fallidas', 'Estado', 'Último error'], array_map(
                fn ($f) => [$f->tipo, $f->exitosas, $f->requeridas, $f->fallidas, $f->estado, mb_strimwidth((string) $f->ultimo_error, 0, 60, '…')], array_values($filas),
            ));

            return collect($filas)->every(fn ($f) => $f->estado === 'completada') ? self::SUCCESS : self::FAILURE;
        });
    }
}
