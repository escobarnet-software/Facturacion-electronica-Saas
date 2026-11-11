<?php

namespace Database\Seeders;

use App\Dte\Catalogos\SincronizadorCatalogos;
use Illuminate\Database\Seeder;

/** Siembra los catálogos oficiales desde docs/oficial/Catalogos.xlsx (idempotente). */
class CatalogosSeeder extends Seeder
{
    public function run(SincronizadorCatalogos $sincronizador): void
    {
        $sincronizador->aplicar(base_path('docs/oficial/Catalogos.xlsx'));
    }
}
