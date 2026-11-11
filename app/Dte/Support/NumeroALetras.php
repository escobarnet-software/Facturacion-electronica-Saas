<?php

declare(strict_types=1);

namespace App\Dte\Support;

use App\Support\Decimal;
use InvalidArgumentException;

/**
 * Convierte un monto a letras para el campo resumen.totalLetras.
 *
 * Sigue el estilo del ejemplo oficial del Manual Funcional v2.0
 * ("Cuatrocientos cincuenta y dos con 47/100 dólares"), en mayúsculas:
 *   6.50    → "SEIS CON 50/100 DÓLARES"
 *   1130.00 → "UN MIL CIENTO TREINTA CON 00/100 DÓLARES"
 */
final class NumeroALetras
{
    private const UNIDADES = [
        '', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE',
        'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE',
        'VEINTE', 'VEINTIUNO', 'VEINTIDÓS', 'VEINTITRÉS', 'VEINTICUATRO', 'VEINTICINCO', 'VEINTISÉIS', 'VEINTISIETE', 'VEINTIOCHO', 'VEINTINUEVE',
    ];

    private const DECENAS = [
        3 => 'TREINTA', 4 => 'CUARENTA', 5 => 'CINCUENTA', 6 => 'SESENTA',
        7 => 'SETENTA', 8 => 'OCHENTA', 9 => 'NOVENTA',
    ];

    private const CENTENAS = [
        1 => 'CIENTO', 2 => 'DOSCIENTOS', 3 => 'TRESCIENTOS', 4 => 'CUATROCIENTOS', 5 => 'QUINIENTOS',
        6 => 'SEISCIENTOS', 7 => 'SETECIENTOS', 8 => 'OCHOCIENTOS', 9 => 'NOVECIENTOS',
    ];

    public static function convertir(Decimal|string $monto): string
    {
        $monto = Decimal::of($monto);
        if ($monto->isNegative()) {
            throw new InvalidArgumentException('El monto en letras no puede ser negativo');
        }

        [$entero, $centavos] = explode('.', $monto->toFixed(2));
        $entero = ltrim($entero, '0') ?: '0';
        if (strlen($entero) > 12) {
            throw new InvalidArgumentException('Monto demasiado grande para convertir a letras');
        }

        $letras = $entero === '0' ? 'CERO' : self::enteroALetras((int) $entero);

        return "{$letras} CON {$centavos}/100 DÓLARES";
    }

    private static function enteroALetras(int $n): string
    {
        $partes = [];

        $millones = intdiv($n, 1_000_000);
        $resto = $n % 1_000_000;
        if ($millones > 0) {
            $partes[] = $millones === 1
                ? 'UN MILLÓN'
                : self::apocopar(self::hastaMil($millones)).' MILLONES';
        }

        $miles = intdiv($resto, 1000);
        $unidades = $resto % 1000;
        if ($miles > 0) {
            // Estilo usual en documentos comerciales salvadoreños: "UN MIL".
            $partes[] = self::apocopar(self::hastaMil($miles)).' MIL';
        }
        if ($unidades > 0) {
            $partes[] = self::hastaMil($unidades);
        }

        return implode(' ', $partes);
    }

    /** 1 a 999. */
    private static function hastaMil(int $n): string
    {
        if ($n === 100) {
            return 'CIEN';
        }
        $c = intdiv($n, 100);
        $r = $n % 100;
        $texto = $c > 0 ? self::CENTENAS[$c] : '';

        if ($r > 0) {
            $texto .= ($texto !== '' ? ' ' : '').self::hastaCien($r);
        }

        return $texto;
    }

    /** 1 a 99. */
    private static function hastaCien(int $n): string
    {
        if ($n < 30) {
            return self::UNIDADES[$n];
        }
        $d = intdiv($n, 10);
        $u = $n % 10;

        return self::DECENAS[$d].($u > 0 ? ' Y '.self::UNIDADES[$u] : '');
    }

    /** "UNO" → "UN" y "VEINTIUNO" → "VEINTIÚN" delante de MIL / MILLONES. */
    private static function apocopar(string $texto): string
    {
        if (str_ends_with($texto, 'VEINTIUNO')) {
            return substr($texto, 0, -strlen('VEINTIUNO')).'VEINTIÚN';
        }
        if (str_ends_with($texto, 'UNO')) {
            return substr($texto, 0, -3).'UN';
        }

        return $texto;
    }
}
