<?php

declare(strict_types=1);

namespace App\Dte\Catalogos;

use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

/**
 * Lee el Excel oficial de catálogos de Hacienda (docs/oficial/Catalogos.xlsx).
 *
 * El libro tiene una sola hoja con los 33 catálogos uno debajo del otro:
 *   "CAT-0NN Nombre" → fila de encabezados → filas código/valor.
 * Reglas de lectura (comprobadas contra el Excel V 1.1):
 *  - Los códigos con cero inicial vienen como texto ('01'); los demás como número: se guardan como texto.
 *  - Filas sin código son subtítulos de sección (CAT-015 y CAT-019).
 *  - CAT-008 (distritos) no trae departamento: su código reinicia en 01 con cada departamento, en el orden de CAT-012.
 *  - CAT-013 (municipios) no trae departamento: cada nombre empieza con el nombre del departamento.
 *  - CAT-015 (tributos) repite D4 y D5 en las secciones 1 y 2: el «padre» es el número de sección.
 */
final class LectorCatalogosExcel
{
    /**
     * @return array{version: string, catalogos: array<string, array{nombre: string, valores: list<array{codigo: string, valor: string, padre: string, seccion: string|null}>}>}
     */
    public function leer(string $archivo): array
    {
        if (! is_file($archivo)) {
            throw new RuntimeException("No existe el Excel de catálogos: {$archivo}");
        }

        $reader = new Reader;
        $reader->open($archivo);

        $version = '';
        $catalogos = [];
        $actual = null;
        $esperaEncabezado = false;
        $seccion = null;

        try {
            foreach ($reader->getSheetIterator() as $hoja) {
                foreach ($hoja->getRowIterator() as $fila) {
                    $valores = $fila->toArray(); // indexado por columna (0 = A, 1 = B)
                    $a = $this->texto($valores[0] ?? null);
                    $b = $this->texto($valores[1] ?? null);

                    if ($a === '' && $b === '') {
                        continue;
                    }
                    if ($version === '' && preg_match('/CAT[ÁA]LOGOS.*V\s*([\d.]+)/iu', $a, $m)) {
                        $version = $m[1];

                        continue;
                    }
                    if (preg_match('/^(CAT-\d{3})\s+(.+)$/u', $a, $m) && $b === '') {
                        $actual = $m[1];
                        $catalogos[$actual] = ['nombre' => trim($m[2]), 'valores' => []];
                        $esperaEncabezado = true;
                        $seccion = null;

                        continue;
                    }
                    if ($actual === null) {
                        continue;
                    }
                    if ($esperaEncabezado) {
                        $esperaEncabezado = false;

                        continue;
                    }
                    // Subtítulo de sección: CAT-015 lo trae en la columna A sin valor; CAT-019, en B sin código.
                    if ($b === '' || $a === '') {
                        $seccion = $a !== '' ? $a : $b;

                        continue;
                    }

                    $padre = ($actual === 'CAT-015' && $seccion !== null && preg_match('/^(\d+)\s*-/', $seccion, $s)) ? $s[1] : '';
                    $catalogos[$actual]['valores'][] = ['codigo' => $a, 'valor' => $b, 'padre' => $padre, 'seccion' => $seccion];
                }
                break; // solo la primera hoja
            }
        } finally {
            $reader->close();
        }

        if ($catalogos === []) {
            throw new RuntimeException('El Excel no contiene catálogos con el formato esperado (CAT-0NN).');
        }

        $this->asignarDepartamentos($catalogos);

        return ['version' => $version !== '' ? $version : 'desconocida', 'catalogos' => $catalogos];
    }

    /**
     * @param  array<string, array{nombre: string, valores: list<array{codigo: string, valor: string, padre: string, seccion: string|null}>}>  $catalogos
     */
    private function asignarDepartamentos(array &$catalogos): void
    {
        $departamentos = [];
        foreach ($catalogos['CAT-012']['valores'] ?? [] as $d) {
            if ($d['codigo'] !== '00') {
                $departamentos[$d['codigo']] = $this->normalizar($d['valor']);
            }
        }
        if ($departamentos === []) {
            return;
        }
        $codigosDepto = array_keys($departamentos);

        // Distritos: un nuevo departamento empieza cada vez que el código vuelve a 01.
        if (isset($catalogos['CAT-008'])) {
            $indice = -1;
            foreach ($catalogos['CAT-008']['valores'] as $i => $v) {
                if ($v['codigo'] === '00') {
                    $catalogos['CAT-008']['valores'][$i]['padre'] = '00';

                    continue;
                }
                if ($v['codigo'] === '01') {
                    $indice++;
                }
                $padre = $codigosDepto[$indice] ?? throw new RuntimeException('CAT-008 tiene más grupos de distritos que departamentos en CAT-012.');
                $catalogos['CAT-008']['valores'][$i]['padre'] = (string) $padre;
            }
        }

        // Municipios: el nombre empieza con el nombre del departamento (se elige la coincidencia más larga).
        if (isset($catalogos['CAT-013'])) {
            foreach ($catalogos['CAT-013']['valores'] as $i => $v) {
                if ($v['codigo'] === '00') {
                    $catalogos['CAT-013']['valores'][$i]['padre'] = '00';

                    continue;
                }
                $nombre = $this->normalizar($v['valor']);
                $mejor = null;
                foreach ($departamentos as $codigo => $depto) {
                    if (str_starts_with($nombre, $depto.' ') && ($mejor === null || strlen($depto) > strlen($departamentos[$mejor]))) {
                        $mejor = $codigo;
                    }
                }
                if ($mejor === null) {
                    throw new RuntimeException("No se pudo ubicar el departamento del municipio «{$v['valor']}».");
                }
                $catalogos['CAT-013']['valores'][$i]['padre'] = (string) $mejor;
            }
        }
    }

    private function texto(mixed $valor): string
    {
        if (is_float($valor) && floor($valor) === $valor) {
            $valor = (int) $valor;
        }

        return trim(preg_replace('/\s+/u', ' ', (string) $valor) ?? '');
    }

    private function normalizar(string $texto): string
    {
        $sinTildes = strtr(mb_strtoupper($texto), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U']);

        return trim($sinTildes);
    }
}
