<?php

declare(strict_types=1);

namespace App\Api;

use App\Dte\Excepciones\DteException;

/**
 * Montos que llegan por la API. Se recomienda enviarlos como texto ("0.75"). Si llegan como número
 * JSON, PHP ya los convirtió a float: se aceptan solo si su representación más corta es exacta
 * (hasta 15 cifras significativas y 8 decimales); si no, se pide enviarlos como texto.
 */
final class MontoApi
{
    public static function texto(mixed $valor, string $campo): string
    {
        if (is_int($valor)) {
            return (string) $valor;
        }
        if (is_float($valor)) {
            $texto = (string) json_encode($valor);
            $cifras = strlen(ltrim(str_replace(['-', '.'], '', $texto), '0'));
            if (str_contains($texto, 'e') || str_contains($texto, 'E') || $cifras > 15) {
                throw new DteException("El valor de {$campo} no es exacto como número: envíelo como texto, por ejemplo \"0.75\".");
            }
            $valor = $texto;
        }
        if (! is_string($valor) || ! preg_match('/^\d{1,11}(\.\d{1,8})?$/', $valor)) {
            throw new DteException("El valor de {$campo} debe ser un monto positivo con hasta 8 decimales.");
        }

        return $valor;
    }
}
