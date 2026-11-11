<?php

declare(strict_types=1);

namespace Tests\Unit\Dte;

use App\Dte\Excepciones\DteException;
use App\Dte\Factura\FacturaBuilder;
use App\Dte\Modelos\Emisor;
use App\Dte\Modelos\LineaVenta;
use App\Dte\Modelos\Pago;
use App\Dte\Modelos\Receptor;
use App\Dte\Modelos\Venta;
use App\Support\Decimal;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class FacturaBuilderTest extends TestCase
{
    private const CODIGO = '8F2A6C1E-3B4D-4E5F-9A0B-1C2D3E4F5A6B';

    private function emisor(): Emisor
    {
        return new Emisor(
            nit: '06140000000000',
            nrc: '1234567',
            nombre: 'PUPUSERIA LA ESQUINA S.A. DE C.V.',
            codActividad: '56101',
            descActividad: 'Restaurantes',
            nombreComercial: 'Pupusería La Esquina',
            departamento: '06',
            municipio: '23',
            distrito: '14',
            complemento: 'Calle Principal 123',
            telefono: '22220000',
            correo: 'ventas@laesquina.com',
            codEstable: 'M001',
            codPuntoVenta: 'P001',
        );
    }

    private function venta(array $lineas, ?Receptor $receptor = null, array $pagos = []): Venta
    {
        return new Venta(
            lineas: $lineas,
            receptor: $receptor ?? Receptor::consumidorFinal(),
            correlativo: 1,
            fechaHora: new DateTimeImmutable('2026-11-10 15:30:00', new DateTimeZone('America/El_Salvador')),
            pagos: $pagos,
        );
    }

    private function construir(Venta $venta): array
    {
        $r = (new FacturaBuilder($this->emisor(), '00'))->construir($venta, self::CODIGO);

        return [$r, $r->documento()];
    }

    public function test_pupuseria_del_curso(): void
    {
        [$r, $doc] = $this->construir($this->venta([
            new LineaVenta('Pupusa revuelta', '6', '0.75'),
            new LineaVenta('Horchata', '2', '1.00'),
        ]));

        $this->assertSame('6.50', $r->totalPagar->toFixed(2));
        $this->assertSame('0.75', $r->totalIva->toFixed(2));
        $this->assertSame('DTE-01-M001P001-000000000000001', $r->numeroControl);

        // Las líneas: 4.50 y 2.00 con IVA contenido a 8 decimales.
        $this->assertStringContainsString('"ventaGravada":4.5,', $r->json);
        $this->assertStringContainsString('"ivaItem":0.51769912}', $r->json);
        $this->assertStringContainsString('"ivaItem":0.2300885}', $r->json);

        // El resumen.
        $this->assertStringContainsString('"totalGravada":6.5,', $r->json);
        $this->assertStringContainsString('"totalIva":0.75,', $r->json);
        $this->assertStringContainsString('"totalPagar":6.5,', $r->json);
        $this->assertSame('SEIS CON 50/100 DÓLARES', $doc['resumen']['totalLetras']);
        $this->assertSame('01', $doc['resumen']['pagos'][0]['codigo']);

        // Identificación.
        $this->assertSame(2, $doc['identificacion']['version']);
        $this->assertSame('2026-11-10', $doc['identificacion']['fecEmi']);
        $this->assertSame('15:30:00', $doc['identificacion']['horEmi']);
        $this->assertSame(self::CODIGO, $doc['identificacion']['codigoGeneracion']);

        // Consumidor final: receptor con todos sus campos en null.
        $this->assertNull($doc['receptor']['nombre']);
        $this->assertNull($doc['receptor']['direccion']);
    }

    public function test_todas_las_secciones_del_esquema_estan_presentes(): void
    {
        [, $doc] = $this->construir($this->venta([new LineaVenta('Café', '1', '0.85')]));

        foreach (['identificacion', 'documentoRelacionado', 'emisor', 'receptor', 'otrosDocumentos',
            'ventaTercero', 'cuerpoDocumento', 'resumen', 'apendice'] as $seccion) {
            $this->assertArrayHasKey($seccion, $doc);
        }
    }

    public function test_hora_se_convierte_a_hora_de_el_salvador(): void
    {
        $venta = new Venta(
            lineas: [new LineaVenta('Café', '1', '0.85')],
            receptor: Receptor::consumidorFinal(),
            correlativo: 7,
            fechaHora: new DateTimeImmutable('2026-11-10 21:30:00', new DateTimeZone('UTC')),
        );
        [, $doc] = $this->construir($venta);

        $this->assertSame('2026-11-10', $doc['identificacion']['fecEmi']);
        $this->assertSame('15:30:00', $doc['identificacion']['horEmi']); // UTC-6
    }

    public function test_muchas_lineas_cuadran_dentro_de_la_holgura(): void
    {
        $lineas = [];
        for ($i = 1; $i <= 37; $i++) {
            $lineas[] = new LineaVenta("Producto {$i}", (string) $i, '1.13');
        }
        [$r] = $this->construir($this->venta($lineas));

        // Total = 1.13 × (1 + 2 + ... + 37) = 1.13 × 703 = 794.39
        $this->assertSame('794.39', $r->totalPagar->toFixed(2));
        // IVA contenido = 794.39 × 13 / 113 = 91.39
        $this->assertSame('91.39', $r->totalIva->toFixed(2));
    }

    public function test_cantidades_con_decimales(): void
    {
        // 2.5 libras de queso a $3.99 la libra = 9.975 → 9.975 a 8 decimales; total 9.98
        [$r, $doc] = $this->construir($this->venta([new LineaVenta('Queso duro', '2.5', '3.99', uniMedida: 36)]));

        $this->assertSame('9.98', $r->totalPagar->toFixed(2));
        $this->assertStringContainsString('"ventaGravada":9.975,', $r->json);
        $this->assertSame(36, $doc['cuerpoDocumento'][0]['uniMedida']);
    }

    public function test_varias_formas_de_pago(): void
    {
        [, $doc] = $this->construir($this->venta(
            [new LineaVenta('Almuerzo', '2', '5.00')],
            pagos: [new Pago('01', '4.00'), new Pago('02', '6.00', referencia: 'AUT-1234')],
        ));

        $this->assertCount(2, $doc['resumen']['pagos']);
        $this->assertSame('AUT-1234', $doc['resumen']['pagos'][1]['referencia']);
    }

    public function test_rechaza_pagos_que_no_cuadran(): void
    {
        $this->expectException(DteException::class);
        $this->construir($this->venta(
            [new LineaVenta('Almuerzo', '2', '5.00')],
            pagos: [new Pago('01', '4.00'), new Pago('02', '5.00')],
        ));
    }

    public function test_venta_de_25000_o_mas_exige_identificar_al_cliente(): void
    {
        $this->expectException(DteException::class);
        $this->construir($this->venta([new LineaVenta('Vehículo', '1', '25000.00')]));
    }

    public function test_venta_grande_con_cliente_identificado(): void
    {
        $cliente = new Receptor(tipoDocumento: '13', numDocumento: '00000000-0', nombre: 'MARIA LOPEZ');
        [$r] = $this->construir($this->venta([new LineaVenta('Vehículo', '1', '25000.00')], $cliente));

        $this->assertSame('25000.00', $r->totalPagar->toFixed(2));
    }

    public function test_rechaza_cantidad_cero(): void
    {
        $this->expectException(DteException::class);
        $this->construir($this->venta([new LineaVenta('Nada', '0', '1.00')]));
    }

    public function test_rechaza_factura_sin_lineas(): void
    {
        $this->expectException(DteException::class);
        $this->construir($this->venta([]));
    }

    public function test_rechaza_ambiente_invalido(): void
    {
        $this->expectException(DteException::class);
        new FacturaBuilder($this->emisor(), '02');
    }

    public function test_iva_contenido(): void
    {
        $this->assertSame('1.30', FacturaBuilder::ivaContenido(Decimal::of('11.30'), 2)->toFixed(2));
        $this->assertSame('0.51769912', (string) FacturaBuilder::ivaContenido(Decimal::of('4.50'), 8));
    }
}
