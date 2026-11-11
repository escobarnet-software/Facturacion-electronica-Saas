<?php

declare(strict_types=1);

namespace App\Dte\Support;

use InvalidArgumentException;

/**
 * Número de control del DTE (Manual Funcional v2.0, sección XI).
 *
 *   DTE-01-M001P001-000000000000001
 *   │   │  │   │    └ correlativo de 15 dígitos (no se repite en el año calendario)
 *   │   │  │   └ punto de venta: "P" + 3 dígitos
 *   │   │  └ establecimiento: "M", "B", "S" o "P" + 3 dígitos
 *   │   └ tipo de DTE (catálogo CAT-002)
 *   └ siempre "DTE"
 *
 * Total: 31 caracteres contando los guiones.
 */
final class NumeroControl
{
    public static function generar(string $tipoDte, string $codEstable, string $codPuntoVenta, int $correlativo): string
    {
        if (!preg_match('/^\d{2}$/', $tipoDte)) {
            throw new InvalidArgumentException("Tipo de DTE inválido: {$tipoDte}");
        }
        if (!preg_match('/^[MBSP]\d{3}$/', $codEstable)) {
            throw new InvalidArgumentException("Código de establecimiento inválido (ejemplo correcto: M001): {$codEstable}");
        }
        if (!preg_match('/^P\d{3}$/', $codPuntoVenta)) {
            throw new InvalidArgumentException("Código de punto de venta inválido (ejemplo correcto: P001): {$codPuntoVenta}");
        }
        if ($correlativo < 1 || $correlativo > 999_999_999_999_999) {
            throw new InvalidArgumentException("Correlativo fuera de rango: {$correlativo}");
        }

        return sprintf('DTE-%s-%s%s-%015d', $tipoDte, $codEstable, $codPuntoVenta, $correlativo);
    }

    public static function esValido(string $numero, ?string $tipoDte = null): bool
    {
        $tipo = $tipoDte !== null ? preg_quote($tipoDte, '/') : '\d{2}';

        return strlen($numero) === 31
            && (bool) preg_match('/^DTE-' . $tipo . '-[MBSP]\d{3}P\d{3}-\d{15}$/', $numero);
    }
}
