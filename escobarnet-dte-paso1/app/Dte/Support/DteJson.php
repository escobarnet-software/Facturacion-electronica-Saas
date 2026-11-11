<?php

declare(strict_types=1);

namespace App\Dte\Support;

use JsonException;

/**
 * Convierte el arreglo del DTE en texto JSON escribiendo cada Decimal como
 * número JSON EXACTO (sin pasar nunca por float).
 *
 *   ['totalPagar' => Decimal::of('6.50')]  →  {"totalPagar":6.5}
 *
 * También cumple dos reglas del Manual Tecnológico v2.0 (sección 6.1):
 *   - todo en UTF-8 (tildes y eñes sin escapar),
 *   - JSON limpio, sin caracteres sobrantes.
 */
final class DteJson
{
    private const MARCA = '__DEC__';

    /** @throws JsonException */
    public static function encode(array $data, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = json_encode(self::marcar($data), $flags);

        return preg_replace('/"' . self::MARCA . '(-?\d+(?:\.\d+)?)' . self::MARCA . '"/', '$1', $json);
    }

    private static function marcar(mixed $value): mixed
    {
        if ($value instanceof Decimal) {
            return self::MARCA . $value->toPlainString() . self::MARCA;
        }
        if (is_float($value)) {
            throw new \LogicException('No se permiten float en un DTE: use Decimal.');
        }
        if (is_array($value)) {
            return array_map([self::class, 'marcar'], $value);
        }

        return $value;
    }
}
