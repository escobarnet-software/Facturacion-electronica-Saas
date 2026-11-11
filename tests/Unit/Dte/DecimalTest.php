<?php

declare(strict_types=1);

namespace Tests\Unit\Dte;

use App\Support\Decimal;
use PHPUnit\Framework\TestCase;

final class DecimalTest extends TestCase
{
    public function test_suma_exacta_sin_errores_de_float(): void
    {
        // Con float: 0.1 + 0.2 = 0.30000000000000004
        $this->assertSame('0.3', (string) Decimal::of('0.1')->add('0.2'));
        $this->assertTrue(Decimal::of('0.1')->add('0.2')->equals('0.3'));
    }

    public function test_redondeo_del_resumen_segun_manual_funcional(): void
    {
        // Ejemplos oficiales del Manual Funcional v2.0, sección XXI.
        $this->assertSame('5.66', Decimal::of('5.664')->toFixed(2));
        $this->assertSame('5.67', Decimal::of('5.665')->toFixed(2));
        $this->assertSame('5.67', Decimal::of('5.666')->toFixed(2));
    }

    public function test_redondeo_a_ocho_decimales_segun_manual_funcional(): void
    {
        // Cantidad 1.87654321 9 87654 → 1.87654322 ; Precio 3.55555555 4 → 3.55555555
        $this->assertSame('1.87654322', Decimal::of('1.87654321987654')->toFixed(8));
        $this->assertSame('3.55555555', Decimal::of('3.555555554')->toFixed(8));
        $this->assertSame('5.67215367', Decimal::of('5.67215366775307')->toFixed(8));
    }

    public function test_redondeo_de_negativos_se_aleja_de_cero(): void
    {
        $this->assertSame('-5.67', Decimal::of('-5.665')->toFixed(2));
        $this->assertSame('-5.66', Decimal::of('-5.664')->toFixed(2));
    }

    public function test_acarreo_al_redondear(): void
    {
        $this->assertSame('1.00', Decimal::of('0.995')->toFixed(2));
        $this->assertSame('10.00', Decimal::of('9.999')->toFixed(2));
    }

    public function test_multiplicacion_y_division(): void
    {
        $this->assertSame('4.50', (string) Decimal::of('6')->mul('0.75'));
        // IVA contenido en $6.50: 6.50 × 13 / 113 = 0.747787610...
        $this->assertSame('0.74778761', (string) Decimal::of('6.50')->mul(13)->div(113, 8));
        $this->assertSame('0.75', (string) Decimal::of('6.50')->mul(13)->div(113, 2));
        $this->assertSame('-0.5', (string) Decimal::of('1')->div('-2', 1));
    }

    public function test_numeros_grandes_sin_desbordamiento(): void
    {
        // (10^11 − 10^-8)² = 10^22 − 2000 + 10^-16 : con float esto se pierde por completo.
        $a = Decimal::of('99999999999.99999999');
        $this->assertSame('9999999999999999998000.0000000000000001', (string) $a->mul($a));
    }

    public function test_resta_y_comparacion(): void
    {
        $this->assertSame('-1.25', (string) Decimal::of('1.00')->sub('2.25'));
        $this->assertSame(1, Decimal::of('2')->compareTo('1.999'));
        $this->assertSame(-1, Decimal::of('-3')->compareTo('0'));
        $this->assertSame(0, Decimal::of('1.50')->compareTo('1.5'));
    }

    public function test_texto_sin_ceros_sobrantes_para_el_json(): void
    {
        $this->assertSame('6.5', Decimal::of('6.50')->toPlainString());
        $this->assertSame('11', Decimal::of('11.00')->toPlainString());
        $this->assertSame('0', Decimal::of('0.000')->toPlainString());
        $this->assertSame('0.2300885', Decimal::of('0.23008850')->toPlainString());
    }

    public function test_no_acepta_float(): void
    {
        $this->expectException(\TypeError::class);
        Decimal::of(0.1); // @phpstan-ignore-line
    }

    public function test_no_acepta_texto_invalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Decimal::of('1,50');
    }
}
