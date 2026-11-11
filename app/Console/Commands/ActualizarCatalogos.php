<?php

namespace App\Console\Commands;

use App\Dte\Catalogos\SincronizadorCatalogos;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('dte:catalogos:actualizar {archivo? : Excel oficial de catálogos (por defecto docs/oficial/Catalogos.xlsx)} {--aplicar : Guarda los cambios; sin esta opción solo muestra las diferencias}')]
#[Description('Compara los catálogos oficiales de Hacienda (Excel) con la base de datos y, con --aplicar, los actualiza.')]
class ActualizarCatalogos extends Command
{
    public function handle(SincronizadorCatalogos $sincronizador): int
    {
        $archivo = (string) ($this->argument('archivo') ?: base_path('docs/oficial/Catalogos.xlsx'));

        $d = $this->option('aplicar') ? $sincronizador->aplicar($archivo) : $sincronizador->comparar($archivo);

        $this->info("Catálogos oficiales versión {$d['version']} · ".count($d['conteo']).' catálogos · '.array_sum($d['conteo']).' valores');
        $this->line('Nuevos: '.count($d['nuevos']).' · Cambiados: '.count($d['cambiados']).' · Retirados: '.count($d['retirados']));

        foreach ($d['cambiados'] as $c) {
            $this->line("  ~ {$c['catalogo']} {$c['codigo']}: «{$c['valor_anterior']}» → «{$c['valor']}»");
        }
        foreach ($d['retirados'] as $r) {
            $this->line("  - {$r['catalogo']} {$r['codigo']}: {$r['valor']}");
        }
        if (count($d['nuevos']) > 0 && count($d['nuevos']) <= 50) {
            foreach ($d['nuevos'] as $n) {
                $this->line("  + {$n['catalogo']} {$n['codigo']}: {$n['valor']}");
            }
        }

        if (! $this->option('aplicar') && (count($d['nuevos']) + count($d['cambiados']) + count($d['retirados'])) > 0) {
            $this->comment('Solo se mostraron las diferencias. Use --aplicar para guardarlas.');
        }

        return self::SUCCESS;
    }
}
