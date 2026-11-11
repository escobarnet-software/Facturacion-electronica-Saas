<?php

declare(strict_types=1);

namespace Tests\Unit\Dte;

use App\Dte\Contribuyente\CreditoFiscalBuilder;
use App\Dte\Contribuyente\DocumentoArmado;
use App\Dte\Contribuyente\DocumentoContribuyente;
use App\Dte\Contribuyente\DocumentoRelacionado;
use App\Dte\Contribuyente\Linea;
use App\Dte\Contribuyente\NotaCreditoBuilder;
use App\Dte\Contribuyente\NotaDebitoBuilder;
use App\Dte\Contribuyente\NotaRemisionBuilder;
use App\Dte\Contribuyente\ReceptorContribuyente;
use App\Dte\Excepciones\DteException;
use App\Dte\Modelos\Emisor;
use App\Dte\Modelos\Pago;
use App\Dte\Validacion\MultiploExacto;
use App\Dte\Validacion\ValidadorEsquema;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Paso 5 — Crédito Fiscal (03), Nota de Remisión (04), Nota de Crédito (05) y Nota de Débito (06).
 *
 * Terminado cuando: cada uno valida contra su esquema oficial y cuadran al centavo los ejemplos
 * de ESPECIFICACION §6.4 ($7,039.90, $2,599.00, $761.20, $107.35) y el de la NC ($95.80).
 */
final class ContribuyenteTest extends TestCase
{
    private const CCF_RELACIONADO = 'AC54E9DB-79C5-42CE-B430-EC552C97EFBA';

    private function emisor(): Emisor
    {
        return new Emisor(
            nit: '06142803901121', nrc: '1234567', nombre: 'ALMACENES DE PRUEBA, S.A. DE C.V.',
            codActividad: '47711', descActividad: 'Venta al por menor de prendas de vestir',
            nombreComercial: 'Almacenes de prueba', departamento: '06', municipio: '23', distrito: '14',
            complemento: 'Colonia Escalón, San Salvador', telefono: '22223333', correo: 'ventas@almacen.test',
            codEstable: 'M001', codPuntoVenta: 'P001',
        );
    }

    private function receptor(): ReceptorContribuyente
    {
        return new ReceptorContribuyente(
            nit: '06140101851014', nrc: '765432', nombre: 'CLIENTE CONTRIBUYENTE, S.A. DE C.V.',
            codActividad: '46900', descActividad: 'Venta al por mayor de otros productos',
            departamento: '05', municipio: '25', distrito: '01', complemento: 'Santa Tecla, La Libertad',
            correo: 'compras@cliente.test', telefono: '22224444',
        );
    }

    private function fecha(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-05-13 10:00:00', new DateTimeZone('America/El_Salvador'));
    }

    /** @param list<Linea> $lineas */
    private function doc(array $lineas, mixed ...$opciones): DocumentoContribuyente
    {
        return new DocumentoContribuyente($lineas, $this->receptor(), 7, $this->fecha(), ...$opciones);
    }

    private function relacionadoCcf(): DocumentoRelacionado
    {
        return new DocumentoRelacionado('03', self::CCF_RELACIONADO, new DateTimeImmutable('2026-05-13'));
    }

    private function assertCumpleEsquema(DocumentoArmado $d): void
    {
        $errores = (new ValidadorEsquema(__DIR__.'/../../../resources/schemas'))->validar($d->json, $d->tipoDte, $d->version);
        $this->assertSame([], $errores, "El {$d->tipoDte} no cumple su esquema oficial");
    }

    /** @return array<string, mixed> */
    private function resumen(DocumentoArmado $d): array
    {
        return $d->documento()['resumen'];
    }

    // --- Ejemplos oficiales ------------------------------------------------------

    public function test_ccf_25_por_250_con_descuento_de_20_da_7039_90(): void
    {
        $ccf = (new CreditoFiscalBuilder($this->emisor()))->construir($this->doc([Linea::gravada('Mercadería', '25', '250.00', '20.00')]));
        $r = $this->resumen($ccf);

        $this->assertCumpleEsquema($ccf);
        $this->assertSame('6230.00', $ccf->calculo->subTotal->toFixed(2));
        $this->assertSame('809.90', $ccf->calculo->iva->toFixed(2));
        $this->assertSame('7039.90', $ccf->totalPagar()->toFixed(2));
        $this->assertEquals(7039.9, $r['totalPagar']);
        $this->assertSame([['codigo' => '20', 'descripcion' => 'Impuesto al Valor Agregado 13%', 'valor' => 809.9]], $r['tributos']);
        $this->assertSame(['20'], $ccf->documento()['cuerpoDocumento'][0]['tributos']);
    }

    public function test_ccf_100_por_25_con_descuento_global_de_200_da_2599(): void
    {
        $ccf = (new CreditoFiscalBuilder($this->emisor()))->construir($this->doc(
            [Linea::gravada('Mercadería', '100', '25.00')],
            descuentoGlobalGravada: '200.00',
        ));
        $r = $this->resumen($ccf);

        $this->assertCumpleEsquema($ccf);
        $this->assertSame('2300.00', $ccf->calculo->subTotal->toFixed(2));
        $this->assertSame('299.00', $ccf->calculo->iva->toFixed(2));
        $this->assertSame('2599.00', $ccf->totalPagar()->toFixed(2));
        $this->assertEquals(200, $r['descuGravada']);
        $this->assertEquals(200, $r['totalDescu']);
    }

    public function test_ccf_del_manual_funcional_con_descuento_global_retencion_y_abono_da_761_20(): void
    {
        // Manual Funcional v2.0, «Ejemplo de ajuste con NCE a CCFE con Descuento Global y Retención».
        $ccf = (new CreditoFiscalBuilder($this->emisor()))->construir($this->doc(
            [
                Linea::gravada('Camisas/gris', '10', '11.00', '10.00', '00D56'),
                Linea::gravada('Centros', '15', '5.00', '0', '00C25'),
                Linea::gravada('Jeans', '25', '21.00', '25.00', '0056D'),
                Linea::gravada('Cinturón', '5', '20.00', '0', '0D586'),
                Linea::noAfecta('Pagos no afectos', '-20.00'),
            ],
            porcentajeDescuento: '10',
            retenerIva1: true,
        ));
        $c = $ccf->calculo;

        $this->assertCumpleEsquema($ccf);
        $this->assertSame('775.00', $c->totalGravada->toFixed(2));
        $this->assertSame('775.00', $c->subTotalVentas->toFixed(2));
        $this->assertSame('77.50', $c->descuGravada->toFixed(2));
        $this->assertSame('697.50', $c->subTotal->toFixed(2));
        $this->assertSame('90.68', $c->iva->toFixed(2));
        $this->assertSame('788.18', $c->montoTotalOperacion->toFixed(2));
        $this->assertSame('6.98', $c->ivaRete->toFixed(2));
        $this->assertSame('-20.00', $c->totalNoGravado->toFixed(2));
        $this->assertSame('761.20', $c->totalPagar->toFixed(2));
        // El manual imprime $113.50, pero la suma correcta es 10 + 25 + 77.50 = $112.50 (ESPECIFICACION §6.5).
        $this->assertSame('112.50', $c->totalDescu->toFixed(2));
        $this->assertSame('SETECIENTOS SESENTA Y UNO CON 20/100 DÓLARES', $this->resumen($ccf)['totalLetras']);
    }

    public function test_ccf_con_item_de_otros_tipos_de_impuesto_de_95_da_107_35(): void
    {
        $ccf = (new CreditoFiscalBuilder($this->emisor()))->construir($this->doc([Linea::otroImpuesto('Impuesto especial', 'A8', '95.00')]));
        $item = $ccf->documento()['cuerpoDocumento'][0];

        $this->assertCumpleEsquema($ccf);
        $this->assertSame('12.35', $ccf->calculo->iva->toFixed(2));
        $this->assertSame('107.35', $ccf->totalPagar()->toFixed(2));
        $this->assertSame(4, $item['tipoItem']);
        $this->assertSame('A8', $item['codTributo']);
    }

    public function test_nc_del_manual_funcional_devolucion_de_5_jeans_da_95_80(): void
    {
        // Devuelve 5 jeans del CCF anterior: $1 de descuento por jean + 10 % de descuento global → $15 por ítem.
        $nc = (new NotaCreditoBuilder($this->emisor()))->construir($this->doc(
            [
                Linea::gravada('Jeans', '5', '21.00', '15.00', '0056D', self::CCF_RELACIONADO),
                Linea::noAfecta('Pagos no afectos', '-5.00', self::CCF_RELACIONADO),
            ],
            relacionados: [$this->relacionadoCcf()],
            retenerIva1: true,
        ));
        $c = $nc->calculo;
        $item = $nc->documento()['cuerpoDocumento'][0];

        $this->assertCumpleEsquema($nc);
        $this->assertSame('90.00', $c->totalGravada->toFixed(2));
        $this->assertSame('15.00', $c->totalDescu->toFixed(2));
        $this->assertSame('11.70', $c->iva->toFixed(2));
        $this->assertSame('101.70', $c->montoTotalOperacion->toFixed(2));
        $this->assertSame('0.90', $c->ivaRete->toFixed(2));
        $this->assertSame('-5.00', $c->totalNoGravado->toFixed(2));
        $this->assertSame('95.80', $c->totalPagar->toFixed(2));
        // IVA y retención por ítem (NC v4).
        $this->assertEquals(11.7, $item['totalIva']);
        $this->assertEquals(0.9, $item['ivaRete']);
        $this->assertSame(self::CCF_RELACIONADO, $item['numeroDocumento']);
        $this->assertSame([['tipoDocumento' => '03', 'tipoGeneracion' => 2, 'numeroDocumento' => self::CCF_RELACIONADO, 'fechaEmision' => '2026-05-13']], $nc->documento()['documentoRelacionado']);
        $this->assertArrayNotHasKey('pagos', $nc->documento()['resumen']);
    }

    // --- Más casos de cálculo --------------------------------------------------------------

    public function test_ccf_separa_ventas_gravadas_exentas_y_no_sujetas(): void
    {
        $ccf = (new CreditoFiscalBuilder($this->emisor()))->construir($this->doc([
            Linea::gravada('Gravado', '2', '10.00'),
            Linea::exenta('Exento', '1', '5.00'),
            Linea::noSujeta('No sujeto', '3', '1.25'),
        ]));
        $c = $ccf->calculo;

        $this->assertCumpleEsquema($ccf);
        $this->assertSame(['20.00', '5.00', '3.75', '28.75'], [$c->totalGravada->toFixed(2), $c->totalExenta->toFixed(2), $c->totalNoSuj->toFixed(2), $c->subTotalVentas->toFixed(2)]);
        $this->assertSame('2.60', $c->iva->toFixed(2));           // IVA solo sobre lo gravado
        $this->assertSame('31.35', $c->totalPagar->toFixed(2));
        $this->assertNull($ccf->documento()['cuerpoDocumento'][1]['tributos']);
    }

    public function test_ccf_con_percepcion_del_1_por_ciento(): void
    {
        $ccf = (new CreditoFiscalBuilder($this->emisor()))->construir($this->doc([Linea::gravada('Mercadería', '1', '1000.00')], percibirIva1: true));

        $this->assertCumpleEsquema($ccf);
        $this->assertSame('10.00', $ccf->calculo->ivaPerci->toFixed(2));
        $this->assertSame('1140.00', $ccf->totalPagar()->toFixed(2));
    }

    public function test_ccf_con_cantidades_y_precios_de_8_decimales_redondea_half_up(): void
    {
        $ccf = (new CreditoFiscalBuilder($this->emisor()))->construir($this->doc([Linea::gravada('Combustible', '3.333', '1.23456789')]));
        $item = $ccf->documento()['cuerpoDocumento'][0];

        $this->assertCumpleEsquema($ccf);
        $this->assertEquals(4.11481478, $item['ventaGravada']);   // 3.333 × 1.23456789 = 4.114814777… → HALF_UP
        $this->assertSame('4.11', $ccf->calculo->totalGravada->toFixed(2));
        $this->assertSame('0.53', $ccf->calculo->iva->toFixed(2));
        $this->assertSame('4.64', $ccf->totalPagar()->toFixed(2));
    }

    public function test_ccf_con_dos_formas_de_pago_que_suman_el_total(): void
    {
        $ccf = (new CreditoFiscalBuilder($this->emisor()))->construir($this->doc(
            [Linea::gravada('Mercadería', '1', '100.00')],
            pagos: [new Pago('01', '100.00'), new Pago('03', '13.00', 'Tarjeta 0000')],
        ));

        $this->assertCumpleEsquema($ccf);
        $this->assertCount(2, $this->resumen($ccf)['pagos']);
    }

    public function test_nd_aumenta_un_ccf_y_cumple_su_esquema(): void
    {
        $nd = (new NotaDebitoBuilder($this->emisor()))->construir($this->doc(
            [Linea::gravada('Ajuste de precio', '1', '50.00', '0', null, self::CCF_RELACIONADO, 2)],
            relacionados: [$this->relacionadoCcf()],
            percibirIva1: true,
        ));
        $c = $nd->calculo;

        $this->assertCumpleEsquema($nd);
        $this->assertSame(['6.50', '0.50', '57.00'], [$c->iva->toFixed(2), $c->ivaPerci->toFixed(2), $c->totalPagar->toFixed(2)]);
        $this->assertArrayHasKey('numPagoElectronico', $nd->documento()['resumen']);
        $this->assertNull($nd->documento()['identificacion']['fusion']);
    }

    public function test_nr_traslada_bienes_y_cumple_su_esquema(): void
    {
        $nr = (new NotaRemisionBuilder($this->emisor()))->construir($this->doc(
            [Linea::gravada('Mercadería en consignación', '10', '12.50')],
            bienTitulo: '04',
        ));
        $r = $this->resumen($nr);

        $this->assertCumpleEsquema($nr);
        $this->assertSame('04', $nr->documento()['receptor']['bienTitulo']);
        $this->assertEquals(125, $r['subTotal']);
        $this->assertEquals(141.25, $r['montoTotalOperacion']);
        $this->assertArrayNotHasKey('totalPagar', $r);
    }

    public function test_nr_puede_no_tener_valor_de_venta(): void
    {
        $nr = (new NotaRemisionBuilder($this->emisor()))->construir($this->doc([Linea::gravada('Traslado entre bodegas', '5', '0')], bienTitulo: '05'));

        $this->assertCumpleEsquema($nr);
        $this->assertTrue($nr->calculo->montoTotalOperacion->isZero());
        $this->assertNull($this->resumen($nr)['tributos']);
    }

    public function test_el_validador_no_rechaza_montos_exactos_por_error_de_coma_flotante(): void
    {
        $ccf = (new CreditoFiscalBuilder($this->emisor()))->construir($this->doc([Linea::gravada('Mercadería', '1', '99999999.99')]));
        $this->assertCumpleEsquema($ccf); // montos grandes con 2 decimales

        // …pero sí rechaza un monto del resumen con 3 decimales.
        $json = str_replace('"totalPagar":'.$ccf->calculo->totalPagar->toPlainString(), '"totalPagar":112999999.989', $ccf->json);
        $this->assertNotSame($ccf->json, $json);
        $errores = (new ValidadorEsquema(__DIR__.'/../../../resources/schemas'))->validar($json, '03', 4);
        $this->assertArrayHasKey('/resumen/totalPagar', $errores);
    }

    public function test_multiplo_exacto_sin_error_de_coma_flotante(): void
    {
        $this->assertTrue(MultiploExacto::esMultiplo(7039.9, 0.01));
        $this->assertTrue(MultiploExacto::esMultiplo(112999999.99, 0.01));
        $this->assertTrue(MultiploExacto::esMultiplo(0.74778761, 1.0e-8));
        $this->assertTrue(MultiploExacto::esMultiplo(-20, 0.01));
        $this->assertFalse(MultiploExacto::esMultiplo(0.005, 0.01));
        $this->assertFalse(MultiploExacto::esMultiplo(0.123456789, 1.0e-8));
    }

    // --- Validaciones -----------------------------------------------------------------------

    public function test_ccf_exige_el_nrc_del_cliente(): void
    {
        $receptor = new ReceptorContribuyente('06140101851014', null, 'X', '46900', 'Venta al por mayor', '05', '25', '01', 'Santa Tecla', null);
        $this->expectException(DteException::class);
        (new CreditoFiscalBuilder($this->emisor()))->construir(new DocumentoContribuyente([Linea::gravada('A', '1', '1')], $receptor, 1, $this->fecha()));
    }

    public function test_nc_exige_documento_relacionado_y_que_cada_linea_lo_indique(): void
    {
        $builder = new NotaCreditoBuilder($this->emisor());

        try {
            $builder->construir($this->doc([Linea::gravada('Devolución', '1', '10', '0', null, self::CCF_RELACIONADO)]));
            $this->fail('Debió exigir el documento relacionado');
        } catch (DteException $e) {
            $this->assertStringContainsString('documento relacionado', $e->getMessage());
        }

        $this->expectExceptionMessage('debe indicar cuál de los documentos relacionados ajusta');
        $builder->construir($this->doc([Linea::gravada('Devolución', '1', '10')], relacionados: [$this->relacionadoCcf()]));
    }

    public function test_nc_no_admite_descuento_global_ni_ajustar_una_factura(): void
    {
        $builder = new NotaCreditoBuilder($this->emisor());
        $linea = Linea::gravada('Devolución', '1', '10', '0', null, self::CCF_RELACIONADO);

        try {
            $builder->construir($this->doc([$linea], relacionados: [$this->relacionadoCcf()], porcentajeDescuento: '10'));
            $this->fail('Debió rechazar el descuento global');
        } catch (DteException $e) {
            $this->assertStringContainsString('descuento global', $e->getMessage());
        }

        $this->expectException(DteException::class);
        $builder->construir($this->doc([$linea], relacionados: [new DocumentoRelacionado('01', self::CCF_RELACIONADO, $this->fecha())]));
    }

    public function test_nr_exige_titulo_de_los_bienes(): void
    {
        $this->expectExceptionMessage('CAT-025');
        (new NotaRemisionBuilder($this->emisor()))->construir($this->doc([Linea::gravada('Mercadería', '1', '1')]));
    }

    public function test_rechaza_pagos_que_no_suman_el_total(): void
    {
        $this->expectExceptionMessage('Los pagos suman');
        (new CreditoFiscalBuilder($this->emisor()))->construir($this->doc([Linea::gravada('A', '1', '100')], pagos: [new Pago('01', '100.00'), new Pago('02', '1.00')]));
    }

    public function test_rechaza_descuento_mayor_que_la_linea_o_descuento_global_mayor_que_las_ventas(): void
    {
        try {
            (new CreditoFiscalBuilder($this->emisor()))->construir($this->doc([Linea::gravada('A', '1', '10', '11')]));
            $this->fail('Debió rechazar el descuento de la línea');
        } catch (DteException) {
        }

        $this->expectException(DteException::class);
        (new CreditoFiscalBuilder($this->emisor()))->construir($this->doc([Linea::gravada('A', '1', '10')], descuentoGlobalGravada: '10.01'));
    }

    public function test_otros_tributos_exigen_codigo_de_tributo(): void
    {
        $this->expectExceptionMessage('CAT-015');
        (new CreditoFiscalBuilder($this->emisor()))->construir($this->doc([new Linea('Impuesto', '1', '5', tipoItem: 4)]));
    }
}
