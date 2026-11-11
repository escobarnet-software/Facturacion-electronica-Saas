<?php

use App\Dte\Catalogos\LectorCatalogosExcel;
use App\Models\CatValor;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Support\Facades\Artisan;

/*
| Terminado cuando (Paso 3): el conteo de filas por catálogo coincide con el Excel.
| Conteos verificados de forma independiente (openpyxl) contra docs/oficial/Catalogos.xlsx V 1.1:
| filas con código y valor, sin encabezados, subtítulos de sección ni notas al pie.
*/

const CONTEO_EXCEL_V11 = [
    'CAT-001' => 2, 'CAT-002' => 13, 'CAT-003' => 2, 'CAT-004' => 2, 'CAT-005' => 5, 'CAT-006' => 3,
    'CAT-007' => 2, 'CAT-008' => 263, 'CAT-009' => 4, 'CAT-010' => 6, 'CAT-011' => 4, 'CAT-012' => 15,
    'CAT-013' => 45, 'CAT-014' => 40, 'CAT-015' => 49, 'CAT-016' => 3, 'CAT-017' => 12, 'CAT-018' => 3,
    'CAT-019' => 774, 'CAT-020' => 249, 'CAT-021' => 4, 'CAT-022' => 5, 'CAT-023' => 2, 'CAT-024' => 3,
    'CAT-025' => 5, 'CAT-026' => 3, 'CAT-027' => 48, 'CAT-028' => 90, 'CAT-029' => 2, 'CAT-030' => 6,
    'CAT-031' => 11, 'CAT-032' => 2, 'CAT-033' => 4,
];

beforeEach(fn () => $this->seed(CatalogosSeeder::class));

it('siembra los 33 catálogos con el mismo número de filas que el Excel', function () {
    $enBd = CatValor::query()->where('vigente', true)
        ->selectRaw('catalogo, count(*) AS n')->groupBy('catalogo')->orderBy('catalogo')
        ->pluck('n', 'catalogo')->map(fn ($n) => (int) $n)->all();

    expect($enBd)->toBe(CONTEO_EXCEL_V11);

    $leido = (new LectorCatalogosExcel)->leer(base_path('docs/oficial/Catalogos.xlsx'));
    expect(array_map(fn ($c) => count($c['valores']), $leido['catalogos']))->toBe(CONTEO_EXCEL_V11)
        ->and($leido['version'])->toBe('1.1');
});

it('guarda los códigos como texto, con sus ceros iniciales', function () {
    expect(CatValor::existe('CAT-002', '01'))->toBeTrue()
        ->and(CatValor::existe('CAT-002', '11'))->toBeTrue()
        ->and(CatValor::existe('CAT-012', '06'))->toBeTrue()
        ->and(CatValor::existe('CAT-017', '01'))->toBeTrue()
        ->and(CatValor::existe('CAT-022', '36'))->toBeTrue()
        ->and(CatValor::existe('CAT-019', '01111'))->toBeTrue()
        ->and(CatValor::existe('CAT-028', '1000.000'))->toBeTrue()
        ->and(CatValor::existe('CAT-002', '1'))->toBeFalse();
});

it('ubica cada municipio y cada distrito en su departamento', function () {
    expect(CatValor::query()->de('CAT-013', '06')->pluck('valor')->all())
        ->toBe(['SAN SALVADOR NORTE', 'SAN SALVADOR OESTE', 'SAN SALVADOR ESTE', 'SAN SALVADOR CENTRO', 'SAN SALVADOR SUR'])
        ->and(CatValor::query()->de('CAT-008', '01')->count())->toBe(12)   // Ahuachapán
        ->and(CatValor::query()->de('CAT-008', '04')->count())->toBe(33)   // Chalatenango
        ->and(CatValor::query()->de('CAT-008', '06')->where('codigo', '14')->value('valor'))->toBe('SAN SALVADOR')
        ->and(CatValor::query()->where('catalogo', 'CAT-013')->where('padre', '')->count())->toBe(0);
});

it('separa las secciones de tributos (D4 y D5 están en las secciones 1 y 2)', function () {
    expect(CatValor::query()->de('CAT-015', '1')->pluck('codigo')->all())->toContain('20', 'D4', 'D5')
        ->and(CatValor::query()->de('CAT-015', '2')->pluck('codigo')->all())->toContain('A8', 'D4', 'D5');
});

it('el seeder es idempotente', function () {
    $antes = CatValor::query()->count();
    $this->seed(CatalogosSeeder::class);

    expect(CatValor::query()->count())->toBe($antes);
    Artisan::call('dte:catalogos:actualizar');
    expect(Artisan::output())->toContain('Nuevos: 0 · Cambiados: 0 · Retirados: 0');
});

it('el comando muestra las diferencias y solo las guarda con --aplicar', function () {
    CatValor::query()->where(['catalogo' => 'CAT-017', 'codigo' => '01'])->update(['valor' => 'Efectivo (viejo)']);
    CatValor::query()->create(['catalogo' => 'CAT-017', 'codigo' => '98', 'valor' => 'Ya no existe', 'version' => '1.0']);

    Artisan::call('dte:catalogos:actualizar');
    expect(Artisan::output())->toContain('Cambiados: 1')->toContain('Retirados: 1')->toContain('Efectivo (viejo)')
        ->and(CatValor::query()->where(['catalogo' => 'CAT-017', 'codigo' => '01'])->value('valor'))->toBe('Efectivo (viejo)');

    Artisan::call('dte:catalogos:actualizar', ['--aplicar' => true]);
    expect(CatValor::query()->where(['catalogo' => 'CAT-017', 'codigo' => '01'])->value('valor'))->toBe('Billetes y monedas')
        ->and(CatValor::existe('CAT-017', '98'))->toBeFalse()                            // retirado: no vigente…
        ->and(CatValor::query()->where(['catalogo' => 'CAT-017', 'codigo' => '98'])->exists())->toBeTrue(); // …pero no se borra
});
