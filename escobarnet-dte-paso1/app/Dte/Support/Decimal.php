<?php

declare(strict_types=1);

namespace App\Dte\Support;

use InvalidArgumentException;

/**
 * Número decimal EXACTO, sin errores de punto flotante.
 *
 * Regla de oro del motor: todo monto se calcula con Decimal, nunca con float.
 *   - En PHP, 0.1 + 0.2 con float da 0.30000000000000004.
 *   - Con Decimal, Decimal::of('0.1')->add(Decimal::of('0.2')) da exactamente 0.3.
 *
 * Internamente guarda un entero sin límite de tamaño (como texto) y una escala
 * (cuántos dígitos son decimales). No necesita extensiones (ni bcmath ni gmp).
 *
 * Redondeo: HALF_UP, como lo define el Manual Funcional v2.0 de Hacienda
 * ("si la primera posición fuera de rango es 5 o mayor, se eleva la última").
 * Para negativos se redondea alejándose de cero (-5.665 → -5.67).
 */
final class Decimal
{
    /** @param string $digits valor absoluto sin escala, solo dígitos, sin ceros a la izquierda */
    private function __construct(
        private readonly bool $negative,
        private readonly string $digits,
        private readonly int $scale,
    ) {
    }

    // ------------------------------------------------------------------
    // Creación
    // ------------------------------------------------------------------

    /**
     * Crea un Decimal desde texto o entero. NO acepta float a propósito:
     * un float ya trae el error de precisión antes de llegar aquí.
     */
    public static function of(string|int|self $value): self
    {
        if ($value instanceof self) {
            return $value;
        }
        if (is_int($value)) {
            $value = (string) $value;
        }

        $value = trim($value);
        if (!preg_match('/^([+-])?(\d+)(?:\.(\d+))?$/', $value, $m)) {
            throw new InvalidArgumentException("Valor decimal inválido: '{$value}'");
        }

        $negative = ($m[1] ?? '') === '-';
        $int = $m[2];
        $frac = $m[3] ?? '';

        return self::make($negative, $int . $frac, strlen($frac));
    }

    public static function zero(): self
    {
        return new self(false, '0', 0);
    }

    /** Suma una lista de Decimal (o valores convertibles). */
    public static function sum(iterable $values): self
    {
        $total = self::zero();
        foreach ($values as $v) {
            $total = $total->add(self::of($v));
        }

        return $total;
    }

    private static function make(bool $negative, string $digits, int $scale): self
    {
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            $digits = '0';
            $negative = false; // no existe "-0"
        }

        return new self($negative, $digits, $scale);
    }

    // ------------------------------------------------------------------
    // Operaciones
    // ------------------------------------------------------------------

    public function add(string|int|self $other): self
    {
        $o = self::of($other);
        $scale = max($this->scale, $o->scale);
        $a = self::shift($this->digits, $scale - $this->scale);
        $b = self::shift($o->digits, $scale - $o->scale);

        if ($this->negative === $o->negative) {
            return self::make($this->negative, self::addAbs($a, $b), $scale);
        }

        // Signos distintos: se resta el menor del mayor.
        $cmp = self::cmpAbs($a, $b);
        if ($cmp === 0) {
            return self::make(false, '0', $scale);
        }
        if ($cmp > 0) {
            return self::make($this->negative, self::subAbs($a, $b), $scale);
        }

        return self::make($o->negative, self::subAbs($b, $a), $scale);
    }

    public function sub(string|int|self $other): self
    {
        return $this->add(self::of($other)->negate());
    }

    public function mul(string|int|self $other): self
    {
        $o = self::of($other);

        return self::make(
            $this->negative !== $o->negative,
            self::mulAbs($this->digits, $o->digits),
            $this->scale + $o->scale,
        );
    }

    /**
     * División redondeada a $scale decimales con HALF_UP.
     * Ejemplo: Decimal::of('6.50')->mul('13')->div('113', 8) = 0.74778761
     */
    public function div(string|int|self $other, int $scale): self
    {
        $o = self::of($other);
        if ($o->isZero()) {
            throw new InvalidArgumentException('División entre cero');
        }
        if ($scale < 0) {
            throw new InvalidArgumentException('La escala no puede ser negativa');
        }

        // Se calcula un dígito extra (scale + 1) y luego se redondea.
        // Para HALF_UP basta con conocer el primer dígito descartado.
        $k = ($scale + 1) + $o->scale - $this->scale;
        $num = $this->digits;
        $den = $o->digits;
        if ($k >= 0) {
            $num = self::shift($num, $k);
        } else {
            $den = self::shift($den, -$k);
        }

        $q = self::divAbs($num, $den);

        return self::make($this->negative !== $o->negative, $q, $scale + 1)->round($scale);
    }

    /** Redondeo HALF_UP a $scale decimales. */
    public function round(int $scale): self
    {
        if ($scale >= $this->scale) {
            return new self($this->negative, $this->digits, $this->scale);
        }

        $drop = $this->scale - $scale;
        $padded = str_pad($this->digits, $drop + 1, '0', STR_PAD_LEFT);
        $kept = substr($padded, 0, -$drop);
        $firstDropped = (int) $padded[strlen($padded) - $drop];

        if ($firstDropped >= 5) {
            $kept = self::addAbs($kept, '1');
        }

        return self::make($this->negative, $kept, $scale);
    }

    public function negate(): self
    {
        return self::make(!$this->negative, $this->digits, $this->scale);
    }

    public function abs(): self
    {
        return self::make(false, $this->digits, $this->scale);
    }

    // ------------------------------------------------------------------
    // Comparaciones
    // ------------------------------------------------------------------

    /** -1 si es menor, 0 si es igual, 1 si es mayor. */
    public function compareTo(string|int|self $other): int
    {
        $diff = $this->sub($other);
        if ($diff->isZero()) {
            return 0;
        }

        return $diff->negative ? -1 : 1;
    }

    public function equals(string|int|self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function isZero(): bool
    {
        return $this->digits === '0';
    }

    public function isNegative(): bool
    {
        return $this->negative;
    }

    public function isPositive(): bool
    {
        return !$this->negative && !$this->isZero();
    }

    public function scale(): int
    {
        return $this->scale;
    }

    // ------------------------------------------------------------------
    // Salida
    // ------------------------------------------------------------------

    /** Texto con exactamente la escala actual, por ejemplo "6.50". */
    public function __toString(): string
    {
        $padded = str_pad($this->digits, $this->scale + 1, '0', STR_PAD_LEFT);
        $int = $this->scale > 0 ? substr($padded, 0, -$this->scale) : $padded;
        $frac = $this->scale > 0 ? substr($padded, -$this->scale) : '';

        return ($this->negative ? '-' : '') . $int . ($frac !== '' ? '.' . $frac : '');
    }

    /** Texto con un número fijo de decimales (redondeando si hace falta). */
    public function toFixed(int $scale): string
    {
        $r = $this->round($scale);
        if ($r->scale < $scale) {
            $r = new self($r->negative, self::shift($r->digits, $scale - $r->scale), $scale);
        }

        return (string) $r;
    }

    /**
     * Texto sin ceros sobrantes al final: "6.50" → "6.5", "11.00" → "11".
     * Es lo que se escribe como número en el JSON del DTE.
     */
    public function toPlainString(): string
    {
        $s = (string) $this;
        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }

        return $s === '-0' ? '0' : $s;
    }

    // ------------------------------------------------------------------
    // Aritmética de enteros grandes en texto (valores absolutos)
    // ------------------------------------------------------------------

    private static function shift(string $digits, int $places): string
    {
        return $places > 0 && $digits !== '0' ? $digits . str_repeat('0', $places) : $digits;
    }

    private static function cmpAbs(string $a, string $b): int
    {
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';
        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }

        return strcmp($a, $b) <=> 0;
    }

    private static function addAbs(string $a, string $b): string
    {
        $result = '';
        $carry = 0;
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $sum = $carry + ($i >= 0 ? (int) $a[$i--] : 0) + ($j >= 0 ? (int) $b[$j--] : 0);
            $result = ($sum % 10) . $result;
            $carry = intdiv($sum, 10);
        }

        return ltrim($result, '0') ?: '0';
    }

    /** Requiere $a >= $b. */
    private static function subAbs(string $a, string $b): string
    {
        $result = '';
        $borrow = 0;
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        while ($i >= 0) {
            $diff = (int) $a[$i--] - $borrow - ($j >= 0 ? (int) $b[$j--] : 0);
            if ($diff < 0) {
                $diff += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = $diff . $result;
        }

        return ltrim($result, '0') ?: '0';
    }

    private static function mulAbs(string $a, string $b): string
    {
        if ($a === '0' || $b === '0') {
            return '0';
        }
        $la = strlen($a);
        $lb = strlen($b);
        $res = array_fill(0, $la + $lb, 0);
        for ($i = $la - 1; $i >= 0; $i--) {
            $da = (int) $a[$i];
            for ($j = $lb - 1; $j >= 0; $j--) {
                $p = $da * (int) $b[$j] + $res[$i + $j + 1];
                $res[$i + $j + 1] = $p % 10;
                $res[$i + $j] += intdiv($p, 10);
            }
        }

        return ltrim(implode('', $res), '0') ?: '0';
    }

    /** División entera (se descarta el residuo). */
    private static function divAbs(string $num, string $den): string
    {
        $quotient = '';
        $rem = '0';
        $len = strlen($num);
        for ($i = 0; $i < $len; $i++) {
            $rem = ltrim($rem . $num[$i], '0') ?: '0';
            $digit = 0;
            while (self::cmpAbs($rem, $den) >= 0) {
                $rem = self::subAbs($rem, $den);
                $digit++;
            }
            $quotient .= $digit;
        }

        return ltrim($quotient, '0') ?: '0';
    }
}
