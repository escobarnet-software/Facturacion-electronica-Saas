<?php

declare(strict_types=1);

namespace App\Dte\Validacion;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Keywords\MultipleOfKeyword;
use Opis\JsonSchema\Schema;
use Opis\JsonSchema\ValidationContext;

/**
 * «multipleOf» EXACTO para montos del DTE.
 *
 * json_decode convierte 7039.90 en un float que, escrito con muchos decimales, es
 * 7039.89999999999964; la comprobación de Opis lo rechazaba como «no múltiplo de 0.01».
 * Aquí el número se toma en su representación decimal más corta (la misma del JSON
 * original: «7039.9») y se compara con bcmath, sin error de coma flotante.
 */
final class MultiploExacto extends MultipleOfKeyword
{
    public function validate(ValidationContext $context, Schema $schema): ?ValidationError
    {
        $dato = $context->currentData();
        if ((is_int($dato) || is_float($dato)) && self::esMultiplo($dato, $this->number)) {
            return null;
        }

        return parent::validate($context, $schema);
    }

    public static function esMultiplo(int|float $numero, float $divisor): bool
    {
        $n = self::decimal($numero);
        $d = self::decimal($divisor);
        if ($n === null || $d === null || bccomp($d, '0', 20) === 0) {
            return false;
        }

        $escala = max(self::decimales($n), self::decimales($d));
        $cociente = bcdiv($n, $d, 0);

        return bccomp(bcmul($d, $cociente, $escala), $n, $escala) === 0;
    }

    /** Representación decimal más corta del número (la de json_encode), sin exponente. */
    private static function decimal(int|float $numero): ?string
    {
        if (is_int($numero)) {
            return (string) $numero;
        }
        if (! is_finite($numero)) {
            return null;
        }

        $texto = (string) json_encode($numero, JSON_PRESERVE_ZERO_FRACTION);
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?[eE]([+-]?\d+)$/', $texto, $m)) {
            // Notación científica (por ejemplo 1.0e-8): se expande sin pasar por float.
            $digitos = $m[2].$m[3];
            $punto = strlen($m[2]) + (int) $m[4];
            if ($punto <= 0) {
                $texto = $m[1].'0.'.str_repeat('0', -$punto).$digitos;
            } elseif ($punto >= strlen($digitos)) {
                $texto = $m[1].$digitos.str_repeat('0', $punto - strlen($digitos));
            } else {
                $texto = $m[1].substr($digitos, 0, $punto).'.'.substr($digitos, $punto);
            }
        }

        return preg_match('/^-?\d+(\.\d+)?$/', $texto) ? $texto : null;
    }

    private static function decimales(string $numero): int
    {
        $punto = strpos($numero, '.');

        return $punto === false ? 0 : strlen($numero) - $punto - 1;
    }
}
