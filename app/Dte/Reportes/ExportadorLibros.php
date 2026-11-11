<?php

declare(strict_types=1);

namespace App\Dte\Reportes;

use InvalidArgumentException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Exporta los libros de IVA a CSV (separado por punto y coma, UTF-8 con BOM, para Excel en español)
 * y a XLSX. Los montos van como texto exacto con 2 decimales: nunca float.
 */
final class ExportadorLibros
{
    public const LIBROS = [
        'consumidor' => 'Libro de ventas a consumidor final',
        'contribuyentes' => 'Libro de ventas a contribuyentes',
        'compras' => 'Libro de compras',
    ];

    public function __construct(private readonly LibrosIva $libros) {}

    /** @return array{columnas: list<string>, filas: list<list<string>>, totales: array<string, string>} */
    public function datos(string $libro, string $desde, string $hasta): array
    {
        return match ($libro) {
            'consumidor' => $this->libros->ventasConsumidor($desde, $hasta),
            'contribuyentes' => $this->libros->ventasContribuyentes($desde, $hasta),
            'compras' => $this->libros->compras($desde, $hasta),
            default => throw new InvalidArgumentException("Libro desconocido: {$libro}"),
        };
    }

    public function csv(string $libro, string $desde, string $hasta): string
    {
        $libroDatos = $this->datos($libro, $desde, $hasta);
        $out = fopen('php://temp', 'r+');
        assert($out !== false);
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($this->renglones($libroDatos) as $renglon) {
            fputcsv($out, $renglon, ';', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** Escribe el XLSX en un archivo temporal y devuelve su ruta. */
    public function xlsx(string $libro, string $desde, string $hasta): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'libro').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($ruta);
        foreach ($this->renglones($this->datos($libro, $desde, $hasta)) as $renglon) {
            $writer->addRow(Row::fromValues($renglon));
        }
        $writer->close();

        return $ruta;
    }

    /**
     * @param  array{columnas: list<string>, filas: list<list<string>>, totales: array<string, string>}  $d
     * @return list<list<string>>
     */
    private function renglones(array $d): array
    {
        $fijas = count($d['columnas']) - count($d['totales']);

        return [$d['columnas'], ...$d['filas'], ['TOTALES', ...array_fill(0, max(0, $fijas - 1), ''), ...array_values($d['totales'])]];
    }
}
