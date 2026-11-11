<?php

declare(strict_types=1);

namespace App\Dte\Support;

/**
 * Código de generación de un DTE: UUID versión 4 en MAYÚSCULAS.
 * Ejemplo: 8F2A6C1E-3B4D-4E5F-9A0B-1C2D3E4F5A6B
 *
 * Regla: se genera uno NUEVO por cada intento de documento. Si Hacienda
 * rechaza un DTE, el documento corregido lleva un código nuevo.
 */
final class CodigoGeneracion
{
    public const PATRON = '/^[A-F0-9]{8}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{12}$/';

    public static function nuevo(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40); // versión 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80); // variante RFC 4122
        $hex = bin2hex($bytes);

        return strtoupper(sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ));
    }

    public static function esValido(string $codigo): bool
    {
        return (bool) preg_match(self::PATRON, $codigo);
    }
}
