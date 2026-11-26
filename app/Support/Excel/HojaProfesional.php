<?php

declare(strict_types=1);

namespace App\Support\Excel;

use App\Support\Decimal;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderStyle;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Options\PageOrientation;
use OpenSpout\Writer\XLSX\Options\PageSetup;
use OpenSpout\Writer\XLSX\Options\PaperSize;
use OpenSpout\Writer\XLSX\Properties;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Hoja de Excel con presentación profesional: título y datos de la empresa, encabezado de color,
 * filas alternas con bordes, montos con formato de moneda, fila de totales, columnas ajustadas
 * al contenido (sin texto cortado), encabezado fijo y filtros.
 *
 * Los montos llegan como TEXTO exacto (Decimal). Solo al escribir la celda se convierten a número
 * para que Excel pueda sumar y dar formato: es el límite de presentación, no un cálculo.
 */
final class HojaProfesional
{
    private const AZUL = '1F56D6';

    private const AZUL_OSCURO = '172F63';

    private const GRIS_BORDE = 'D5DAE1';

    private const FONDO_ALTERNO = 'F3F7FF';

    private const FONDO_TOTAL = 'E3ECFD';

    private const FORMATO_MONTO = '"$"#,##0.00;[Red]-"$"#,##0.00';

    /**
     * @param  list<array{0: string, 1: 'texto'|'monto'|'entero'}>  $columnas  nombre y tipo de cada columna
     * @param  list<list<string|int|null>>  $filas
     * @param  list<string>  $subtitulos  líneas bajo el título (empresa, período, fecha de generación)
     * @param  array<int, string>|null  $totales  índice de columna → total exacto (texto); null = sin fila de totales
     */
    public function __construct(
        private readonly string $titulo,
        private readonly array $columnas,
        private readonly array $filas,
        private readonly array $subtitulos = [],
        private readonly ?array $totales = null,
        private readonly string $nombreHoja = 'Datos',
    ) {}

    /** Escribe el libro en un archivo (ruta) o en la salida ('php://output'). */
    public function guardar(string $destino): void
    {
        $nCols = max(1, count($this->columnas));
        $options = new Options(
            pageSetup: new PageSetup(PageOrientation::LANDSCAPE, PaperSize::Letter, fitToHeight: 0, fitToWidth: 1),
            properties: new Properties(title: $this->titulo, application: 'Escobar Net DTE', creator: 'Escobar Net DTE', lastModifiedBy: 'Escobar Net DTE', language: 'es-SV'),
        );
        $filaEncabezado = count($this->subtitulos) + 3; // título, subtítulos, línea en blanco
        $options->mergeCells(0, 1, $nCols - 1, 1);
        foreach (array_keys($this->subtitulos) as $i) {
            $options->mergeCells(0, $i + 2, $nCols - 1, $i + 2);
        }

        $writer = new Writer($options);
        $writer->openToFile($destino);
        $hoja = $writer->getCurrentSheet();
        $hoja->setName(mb_substr($this->nombreHoja, 0, 31));
        foreach ($this->anchos() as $col => $ancho) {
            $hoja->setColumnWidth($ancho, $col + 1);
        }
        $hoja->setSheetView((new SheetView)->withFreezeRow($filaEncabezado + 1)->withShowGridLines(false));
        $hoja->setPrintTitleRows('$'.$filaEncabezado.':$'.$filaEncabezado);

        // Título y subtítulos
        $writer->addRow(new Row([Cell::fromValue($this->titulo, (new Style)->withFontBold(true)->withFontSize(16)->withFontColor(self::AZUL_OSCURO))], 28));
        foreach ($this->subtitulos as $linea) {
            $writer->addRow(new Row([Cell::fromValue($linea, (new Style)->withFontSize(10)->withFontColor('5A6B85'))]));
        }
        $writer->addRow(new Row([Cell::fromValue('')]));

        // Encabezado
        $estiloEncabezado = (new Style)->withFontBold(true)->withFontColor('FFFFFF')->withBackgroundColor(self::AZUL)
            ->withCellAlignment(CellAlignment::CENTER)->withCellVerticalAlignment(CellVerticalAlignment::CENTER)
            ->withShouldWrapText(true)->withBorder($this->borde(self::AZUL_OSCURO));
        $writer->addRow(new Row(array_map(fn (array $c) => Cell::fromValue($c[0], $estiloEncabezado), $this->columnas), 30));

        // Datos
        foreach ($this->filas as $n => $fila) {
            $fondo = $n % 2 === 1 ? self::FONDO_ALTERNO : null;
            $celdas = [];
            foreach ($this->columnas as $i => [, $tipo]) {
                $celdas[] = $this->celda($fila[$i] ?? null, $tipo, $fondo, false);
            }
            $writer->addRow(new Row($celdas, 18));
        }

        // Totales
        if ($this->totales !== null) {
            $celdas = [];
            foreach ($this->columnas as $i => [, $tipo]) {
                $valor = $this->totales[$i] ?? ($i === 0 ? 'TOTALES' : null);
                $celdas[] = $this->celda($valor, $i === 0 ? 'texto' : $tipo, self::FONDO_TOTAL, true);
            }
            $writer->addRow(new Row($celdas, 22));
        }

        $ultimaFila = $filaEncabezado + count($this->filas);
        if ($this->filas !== []) {
            $hoja->setAutoFilter(new AutoFilter(0, $filaEncabezado, $nCols - 1, $ultimaFila));
        }
        $writer->close();
    }

    private function celda(string|int|null $valor, string $tipo, ?string $fondo, bool $negrita): Cell
    {
        $estilo = (new Style)->withFontSize(11)->withBorder($this->borde(self::GRIS_BORDE))->withCellVerticalAlignment(CellVerticalAlignment::CENTER);
        if ($fondo !== null) {
            $estilo = $estilo->withBackgroundColor($fondo);
        }
        if ($negrita) {
            $estilo = $estilo->withFontBold(true)->withFontColor(self::AZUL_OSCURO);
        }

        if ($tipo === 'monto' && $valor !== null && $valor !== '' && preg_match('/^-?\d+(\.\d+)?$/', (string) $valor)) {
            // Límite de presentación: el texto exacto (2 decimales) se escribe como número para Excel.
            return Cell::fromValue((float) Decimal::of((string) $valor)->toFixed(2), $estilo->withFormat(self::FORMATO_MONTO)->withCellAlignment(CellAlignment::RIGHT));
        }
        if ($tipo === 'entero' && $valor !== null && preg_match('/^-?\d+$/', (string) $valor)) {
            return Cell::fromValue((int) $valor, $estilo->withFormat('#,##0')->withCellAlignment(CellAlignment::CENTER));
        }

        return Cell::fromValue($valor === null ? '' : (string) $valor, $estilo->withCellAlignment(CellAlignment::LEFT));
    }

    private function borde(string $color): Border
    {
        return new Border(...array_map(
            fn (BorderName $n) => new BorderPart($n, $color, BorderWidth::THIN, BorderStyle::SOLID),
            [BorderName::TOP, BorderName::RIGHT, BorderName::BOTTOM, BorderName::LEFT],
        ));
    }

    /**
     * Ancho de cada columna según su contenido más largo (encabezado, datos y totales), para que nada
     * quede cortado. En caracteres de Excel; entre 10 y 60.
     *
     * @return list<float>
     */
    private function anchos(): array
    {
        $anchos = [];
        foreach ($this->columnas as $i => [$nombre, $tipo]) {
            $max = mb_strlen($nombre) * 0.9; // el encabezado admite dos líneas
            $valores = array_map(fn (array $f) => (string) ($f[$i] ?? ''), $this->filas);
            if ($this->totales !== null) {
                $valores[] = (string) ($this->totales[$i] ?? ($i === 0 ? 'TOTALES' : ''));
            }
            foreach ($valores as $v) {
                // Ancho de presentación: cifras con separador de miles y símbolo de moneda.
                $largo = $tipo === 'monto' && preg_match('/^-?\d+(\.\d+)?$/', $v) ? strlen(number_format((float) $v, 2)) + 2 : mb_strlen($v);
                $max = max($max, $largo);
            }
            $anchos[] = (float) min(60, max(10, ceil($max * 1.15) + 3));
        }

        return $anchos;
    }
}
