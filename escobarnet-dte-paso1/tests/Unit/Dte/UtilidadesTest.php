<?php

declare(strict_types=1);

namespace Tests\Unit\Dte;

use App\Dte\Support\CodigoGeneracion;
use App\Dte\Support\Decimal;
use App\Dte\Support\DteJson;
use App\Dte\Support\NumeroALetras;
use App\Dte\Support\NumeroControl;
use PHPUnit\Framework\TestCase;

final class UtilidadesTest extends TestCase
{
    public function test_numero_a_letras(): void
    {
        $this->assertSame('SEIS CON 50/100 DÓLARES', NumeroALetras::convertir('6.50'));
        $this->assertSame('CERO CON 75/100 DÓLARES', NumeroALetras::convertir('0.75'));
        $this->assertSame('CIEN CON 00/100 DÓLARES', NumeroALetras::convertir('100'));
        $this->assertSame('CIENTO UNO CON 00/100 DÓLARES', NumeroALetras::convertir('101'));
        // Ejemplo oficial: "Cuatrocientos cincuenta y dos con 47/100 dólares"
        $this->assertSame('CUATROCIENTOS CINCUENTA Y DOS CON 47/100 DÓLARES', NumeroALetras::convertir('452.47'));
        $this->assertSame('UN MIL CIENTO TREINTA CON 00/100 DÓLARES', NumeroALetras::convertir('1130'));
        $this->assertSame('VEINTIÚN MIL QUINIENTOS CON 10/100 DÓLARES', NumeroALetras::convertir('21500.10'));
        $this->assertSame('UN MILLÓN DOSCIENTOS MIL CON 00/100 DÓLARES', NumeroALetras::convertir('1200000'));
        $this->assertSame('DOS MILLONES CON 01/100 DÓLARES', NumeroALetras::convertir('2000000.01'));
    }

    public function test_numero_control(): void
    {
        $n = NumeroControl::generar('01', 'M001', 'P001', 1);
        $this->assertSame('DTE-01-M001P001-000000000000001', $n);
        $this->assertSame(31, strlen($n));
        $this->assertTrue(NumeroControl::esValido($n, '01'));
        $this->assertFalse(NumeroControl::esValido($n, '03'));
        $this->assertSame('DTE-03-S012P007-000000000004521', NumeroControl::generar('03', 'S012', 'P007', 4521));
    }

    public function test_numero_control_rechaza_establecimiento_invalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NumeroControl::generar('01', 'X001', 'P001', 1);
    }

    public function test_codigo_generacion_es_uuid_v4_en_mayusculas(): void
    {
        $a = CodigoGeneracion::nuevo();
        $b = CodigoGeneracion::nuevo();
        $this->assertTrue(CodigoGeneracion::esValido($a));
        $this->assertSame(strtoupper($a), $a);
        $this->assertSame('4', $a[14]); // versión 4
        $this->assertNotSame($a, $b);
    }

    public function test_json_escribe_decimales_exactos_como_numeros(): void
    {
        $json = DteJson::encode([
            'total' => Decimal::of('6.50'),
            'iva' => Decimal::of('0.51769912'),
            'grande' => Decimal::of('99999999999.12345678'),
            'texto' => 'Pupusería',
        ]);
        $this->assertSame('{"total":6.5,"iva":0.51769912,"grande":99999999999.12345678,"texto":"Pupusería"}', $json);
    }

    public function test_json_rechaza_float(): void
    {
        $this->expectException(\LogicException::class);
        DteJson::encode(['total' => 6.5]);
    }
}
