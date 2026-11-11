<?php

declare(strict_types=1);

namespace Tests\Unit\Dte;

use App\Dte\Contribuyente\ReceptorContribuyente;
use App\Dte\Especiales\ContableLiquidacionBuilder;
use App\Dte\Especiales\DonacionBuilder;
use App\Dte\Especiales\DteArmado;
use App\Dte\Especiales\ExportacionBuilder;
use App\Dte\Especiales\LiquidacionBuilder;
use App\Dte\Especiales\RetencionBuilder;
use App\Dte\Especiales\SujetoExcluidoBuilder;
use App\Dte\Excepciones\DteException;
use App\Dte\Modelos\Emisor;
use App\Dte\Modelos\Pago;
use App\Dte\Validacion\ValidadorEsquema;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Paso 6 — FSE (14), FEX (11), CR (07) y CD (15): al menos 5 pruebas de cálculo por tipo
 * y validación contra el esquema oficial.
 */
final class EspecialesTest extends TestCase
{
    private function emisor(): Emisor
    {
        return new Emisor(
            nit: '06142803901121', nrc: '1234567', nombre: 'EMPRESA DE PRUEBA, S.A. DE C.V.',
            codActividad: '46900', descActividad: 'Venta al por mayor de otros productos', nombreComercial: 'Empresa de prueba',
            departamento: '06', municipio: '23', distrito: '14', complemento: 'Colonia Escalón, San Salvador',
            telefono: '22223333', correo: 'dte@empresa.test', codEstable: 'M001', codPuntoVenta: 'P001',
        );
    }

    private function fecha(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-11 09:00:00', new DateTimeZone('America/El_Salvador'));
    }

    private function assertCumpleEsquema(DteArmado $d): void
    {
        $errores = (new ValidadorEsquema(__DIR__.'/../../../resources/schemas'))->validar($d->json, $d->tipoDte, $d->version);
        $this->assertSame([], $errores, "El {$d->tipoDte} no cumple su esquema oficial");
    }

    // ===================== FSE (14) =====================

    private function sujeto(): array
    {
        return ['tipoDocumento' => '13', 'numDocumento' => '012345678', 'nombre' => 'Juan Pérez', 'departamento' => '06', 'municipio' => '23', 'distrito' => '14', 'complemento' => 'Mercado Central, San Salvador', 'telefono' => '77778888'];
    }

    private function fse(array $lineas, mixed ...$op): DteArmado
    {
        return (new SujetoExcluidoBuilder)->construir($this->emisor(), $this->sujeto(), $lineas, 3, $this->fecha(), ...$op);
    }

    public function test_fse_compra_simple_sin_retencion(): void
    {
        $d = $this->fse([['descripcion' => 'Maíz', 'cantidad' => '10', 'precio' => '12.50']]);
        $this->assertCumpleEsquema($d);
        $this->assertSame(['125.00', '125.00', '0.00', '125.00'], [$d->total('totalCompra')->toFixed(2), $d->total('subTotal')->toFixed(2), $d->total('reteRenta')->toFixed(2), $d->total('totalPagar')->toFixed(2)]);
        $this->assertArrayNotHasKey('ivaItem', $d->documento()['cuerpoDocumento'][0]);
    }

    public function test_fse_con_descuento_por_linea_y_global(): void
    {
        $d = $this->fse([['descripcion' => 'Frijol', 'cantidad' => '4', 'precio' => '25.00', 'descuento' => '5.00']], descuentoGlobal: '15.00');
        $this->assertCumpleEsquema($d);
        $this->assertSame('95.00', $d->total('totalCompra')->toFixed(2));
        $this->assertSame('80.00', $d->total('totalPagar')->toFixed(2));
        $this->assertEquals(20, $d->documento()['resumen']['totalDescu']);
    }

    public function test_fse_retiene_renta_solo_sobre_servicios(): void
    {
        $d = $this->fse([
            ['descripcion' => 'Servicio de limpieza', 'cantidad' => '1', 'precio' => '300.00', 'tipoItem' => 2],
            ['descripcion' => 'Escobas', 'cantidad' => '2', 'precio' => '5.00', 'tipoItem' => 1],
        ], retenerRenta: true);
        $this->assertCumpleEsquema($d);
        $this->assertSame('30.00', $d->total('reteRenta')->toFixed(2));
        $this->assertSame('280.00', $d->total('totalPagar')->toFixed(2));
    }

    public function test_fse_redondea_la_compra_a_8_decimales_y_el_resumen_a_2(): void
    {
        $d = $this->fse([['descripcion' => 'Café', 'cantidad' => '2.5', 'precio' => '3.33333333']]);
        $this->assertCumpleEsquema($d);
        $this->assertEquals(8.33333333, $d->documento()['cuerpoDocumento'][0]['compra']);
        $this->assertSame('8.33', $d->total('totalPagar')->toFixed(2));
    }

    public function test_fse_pagos_deben_sumar_el_total_y_el_descuento_no_superarlo(): void
    {
        $d = $this->fse([['descripcion' => 'Leña', 'cantidad' => '1', 'precio' => '50']], pagos: [new Pago('01', '20'), new Pago('04', '30', 'Cheque 123')]);
        $this->assertCumpleEsquema($d);
        $this->expectException(DteException::class);
        $this->fse([['descripcion' => 'Leña', 'cantidad' => '1', 'precio' => '50']], descuentoGlobal: '60');
    }

    // ===================== FEX (11, v3) =====================

    private function exterior(): array
    {
        return ['nombre' => 'IMPORTADORA DEL NORTE, INC.', 'tipoDocumento' => '37', 'numDocumento' => 'US-998877', 'descActividad' => 'Importación de alimentos', 'codPais' => 'US', 'nombrePais' => 'Estados Unidos', 'complemento' => 'Houston, Texas', 'tipoPersona' => 2, 'correo' => 'compras@importadora.test'];
    }

    private function fex(array $lineas, array $exportacion = ['tipoItemExpor' => 1, 'recintoFiscal' => '02', 'regimen' => 'EX-1.1000.000', 'tipoRegimen' => 'EX-1'], mixed ...$op): DteArmado
    {
        return (new ExportacionBuilder)->construir($this->emisor(), $exportacion, $this->exterior(), $lineas, 9, $this->fecha(), ...$op);
    }

    public function test_fex_exportacion_de_bienes_tasa_cero(): void
    {
        $d = $this->fex([['descripcion' => 'Café oro', 'cantidad' => '100', 'precio' => '4.50']]);
        $r = $d->documento()['resumen'];
        $this->assertCumpleEsquema($d);
        $this->assertSame('450.00', $d->total('totalPagar')->toFixed(2));
        $this->assertSame([['codigo' => 'C3', 'descripcion' => 'Impuesto al Valor Agregado (exportaciones) 0%', 'valor' => 0]], $r['tributos']);
        $this->assertSame('02', $d->documento()['emisor']['recintoFiscal']);
    }

    public function test_fex_suma_seguro_y_flete_despues_del_descuento_global(): void
    {
        $d = $this->fex([['descripcion' => 'Azúcar', 'cantidad' => '200', 'precio' => '10.00']], porcentajeDescuento: '5', seguro: '35.50', flete: '120.00', codIncoterms: '03', descIncoterms: 'CIF-Costo, seguro y flete');
        $this->assertCumpleEsquema($d);
        $this->assertSame('100.00', $d->total('descuGravada')->toFixed(2));
        $this->assertSame('2055.50', $d->total('montoTotalOperacion')->toFixed(2));   // 2000 − 100 + 35.50 + 120
        $this->assertSame('2055.50', $d->total('totalPagar')->toFixed(2));
    }

    public function test_fex_con_cargo_no_afecto(): void
    {
        $d = $this->fex([
            ['descripcion' => 'Textiles', 'cantidad' => '50', 'precio' => '8.00'],
            ['descripcion' => 'Cargo por embalaje especial', 'cantidad' => '1', 'precio' => '0', 'noGravado' => '25.00', 'uniMedida' => 99],
        ]);
        $this->assertCumpleEsquema($d);
        $this->assertSame('425.00', $d->total('totalPagar')->toFixed(2));
    }

    public function test_fex_de_servicios_no_lleva_recinto_ni_regimen(): void
    {
        $d = $this->fex([['descripcion' => 'Desarrollo de software', 'cantidad' => '1', 'precio' => '5000', 'tipoItem' => 2]], ['tipoItemExpor' => 2]);
        $this->assertCumpleEsquema($d);
        $this->assertNull($d->documento()['emisor']['recintoFiscal']);
        $this->assertSame('5000.00', $d->total('totalPagar')->toFixed(2));
    }

    public function test_fex_de_bienes_exige_recinto_y_regimen(): void
    {
        $this->expectExceptionMessage('recinto fiscal');
        $this->fex([['descripcion' => 'Café', 'cantidad' => '1', 'precio' => '1']], ['tipoItemExpor' => 1]);
    }

    public function test_fex_redondeo_de_lineas_con_decimales(): void
    {
        $d = $this->fex([['descripcion' => 'Miel', 'cantidad' => '3.75', 'precio' => '2.66666667', 'descuento' => '0.33333333']]);
        $this->assertCumpleEsquema($d);
        $this->assertEquals(9.66666668, $d->documento()['cuerpoDocumento'][0]['ventaGravada']);
        $this->assertSame('9.67', $d->total('totalPagar')->toFixed(2));
    }

    // ===================== CR (07) =====================

    private function retenido(): ReceptorContribuyente
    {
        return new ReceptorContribuyente('06140101851014', '765432', 'PROVEEDOR, S.A. DE C.V.', '46900', 'Venta al por mayor', '05', '25', '01', 'Santa Tecla', 'facturas@proveedor.test');
    }

    private function cr(array $docs): DteArmado
    {
        return (new RetencionBuilder)->construir($this->emisor(), $this->retenido(), $docs, 4, $this->fecha());
    }

    private function docRet(string $monto, string $codigo = '22', array $extra = []): array
    {
        return ['tipoDte' => '03', 'numeroDocumento' => 'C3AF84A7-8BB8-4867-ABE4-F40BB3AE3052', 'fechaEmision' => '2026-11-10', 'montoSujetoGrav' => $monto, 'codigoRetencionMH' => $codigo, 'descripcion' => 'Compra de mercadería', ...$extra];
    }

    public function test_cr_retencion_del_1_por_ciento(): void
    {
        $d = $this->cr([$this->docRet('1500.00')]);
        $this->assertCumpleEsquema($d);
        $this->assertSame(['1500.00', '195.00', '15.00'], [$d->total('totalSujetoRetencion')->toFixed(2), $d->total('totalIva')->toFixed(2), $d->total('totalIvaRetenido')->toFixed(2)]);
        $this->assertSame('QUINCE CON 00/100 DÓLARES', $d->documento()['resumen']['totalLetras']);
    }

    public function test_cr_retencion_del_13_por_ciento(): void
    {
        $d = $this->cr([$this->docRet('200.00', 'C4')]);
        $this->assertCumpleEsquema($d);
        $this->assertSame('26.00', $d->total('totalIvaRetenido')->toFixed(2));
    }

    public function test_cr_varios_documentos_redondea_cada_retencion(): void
    {
        $d = $this->cr([$this->docRet('123.45'), $this->docRet('678.90'), $this->docRet('0.55')]);
        $this->assertCumpleEsquema($d);
        // 1.2345→1.23, 6.789→6.79, 0.0055→0.01
        $this->assertSame('8.03', $d->total('totalIvaRetenido')->toFixed(2));
        $this->assertSame('802.90', $d->total('totalSujetoRetencion')->toFixed(2));
        $this->assertSame('104.38', $d->total('totalIva')->toFixed(2));
    }

    public function test_cr_otros_casos_c9_con_monto_indicado(): void
    {
        $d = $this->cr([$this->docRet('1000.00', 'C9', ['ivaRetenido' => '20.00'])]);
        $this->assertCumpleEsquema($d);
        $this->assertSame('20.00', $d->total('totalIvaRetenido')->toFixed(2));
    }

    public function test_cr_rechaza_codigo_de_retencion_desconocido_o_monto_cero(): void
    {
        try {
            $this->cr([$this->docRet('10.00', 'ZZ')]);
            $this->fail('Debió rechazar el código');
        } catch (DteException) {
        }
        $this->expectException(DteException::class);
        $this->cr([$this->docRet('0')]);
    }

    // ===================== CD (15) =====================

    private function donante(): array
    {
        return ['tipoDocumento' => '36', 'numDocumento' => '06140101851014', 'nombre' => 'DONANTE GENEROSO, S.A.', 'codDomiciliado' => 1, 'codPais' => '9300', 'nrc' => '765432', 'correo' => 'rse@donante.test'];
    }

    private function cd(array $donaciones, array $pagos = []): DteArmado
    {
        return (new DonacionBuilder)->construir($this->emisor(), '36', $this->donante(), $donaciones,
            [['codDocAsociado' => 1, 'descDocumento' => 'Acuerdo de donación', 'detalleDocumento' => 'Acta 15-2026 de junta directiva']],
            5, $this->fecha(), pagos: $pagos);
    }

    public function test_cd_donacion_en_efectivo(): void
    {
        $d = $this->cd([['tipoDonacion' => 1, 'descripcion' => 'Donación para programa de becas', 'cantidad' => '1', 'valorUni' => '2500.00']]);
        $this->assertCumpleEsquema($d);
        $this->assertSame('2500.00', $d->total('valorTotal')->toFixed(2));
        $this->assertEquals(2500, $d->documento()['resumen']['pagos'][0]['montoPago']);
    }

    public function test_cd_bien_usado_resta_la_depreciacion(): void
    {
        $d = $this->cd([['tipoDonacion' => 2, 'descripcion' => 'Ambulancia usada', 'cantidad' => '1', 'valorUni' => '45000.00', 'depreciacion' => '12500.00']]);
        $this->assertCumpleEsquema($d);
        $this->assertSame('32500.00', $d->total('valorTotal')->toFixed(2));
        $this->assertNull($d->documento()['resumen']['pagos']);   // no hubo efectivo
    }

    public function test_cd_mezcla_tipos_y_los_pagos_solo_cuentan_el_efectivo(): void
    {
        $d = $this->cd([
            ['tipoDonacion' => 1, 'descripcion' => 'Efectivo', 'cantidad' => '1', 'valorUni' => '100.00'],
            ['tipoDonacion' => 2, 'descripcion' => 'Sillas de ruedas', 'cantidad' => '3', 'valorUni' => '150.00'],
            ['tipoDonacion' => 3, 'descripcion' => 'Consultas médicas', 'cantidad' => '10', 'valorUni' => '25.00'],
        ], [new Pago('01', '60.00'), new Pago('05', '40.00', 'Transferencia 5566')]);
        $this->assertCumpleEsquema($d);
        $this->assertSame('800.00', $d->total('valorTotal')->toFixed(2));
        $this->assertSame('100.00', $d->total('efectivo')->toFixed(2));
    }

    public function test_cd_solo_los_bienes_llevan_depreciacion(): void
    {
        $this->expectExceptionMessage('depreciación');
        $this->cd([['tipoDonacion' => 1, 'descripcion' => 'Efectivo', 'cantidad' => '1', 'valorUni' => '10', 'depreciacion' => '1']]);
    }

    public function test_cd_documentos_asociados_solo_de_emisor_o_receptor(): void
    {
        $this->expectException(DteException::class);
        (new DonacionBuilder)->construir($this->emisor(), '36', $this->donante(),
            [['tipoDonacion' => 1, 'descripcion' => 'Efectivo', 'cantidad' => '1', 'valorUni' => '10']],
            [['codDocAsociado' => 3, 'descDocumento' => 'Médico', 'detalleDocumento' => 'No aplica en donaciones']], 5, $this->fecha());
    }

    // ===================== CL (08) =====================

    private function cl(array $docs, string $ivaPercibido = '0'): DteArmado
    {
        return (new LiquidacionBuilder)->construir($this->emisor(), $this->retenido(), 1, $docs, 6, $this->fecha(), ivaPercibido: $ivaPercibido);
    }

    private function docLiq(string $tipo, array $valores, bool $invalidado = false): array
    {
        return ['tipoDte' => $tipo, 'numeroDocumento' => 'C3AF84A7-8BB8-4867-ABE4-F40BB3AE3052', 'fechaEmision' => '2026-11-05', 'invalidado' => $invalidado, ...$valores];
    }

    public function test_cl_liquida_un_ccf(): void
    {
        $d = $this->cl([$this->docLiq('03', ['ventaGravada' => '1000.00'])]);
        $this->assertCumpleEsquema($d);
        $this->assertSame(['1000.00', '130.00', '1130.00', '1130.00'], [$d->total('subTotalVentas')->toFixed(2), $d->total('tributo')->toFixed(2), $d->total('montoTotalOperacion')->toFixed(2), $d->total('total')->toFixed(2)]);
        $this->assertEquals(130, $d->documento()['cuerpoDocumento'][0]['ivaItem']);
    }

    public function test_cl_factura_lleva_tributo_20_y_su_iva_de_resumen(): void
    {
        $d = $this->cl([$this->docLiq('01', ['ventaGravada' => '100.00', 'ivaItem' => '13.00'])]);
        $this->assertCumpleEsquema($d);
        $this->assertSame(['20'], $d->documento()['cuerpoDocumento'][0]['tributos']);
        $this->assertSame('113.00', $d->total('total')->toFixed(2));
    }

    public function test_cl_nota_de_credito_resta_y_ccf_invalidado_tambien(): void
    {
        $d = $this->cl([
            $this->docLiq('03', ['ventaGravada' => '500.00']),
            $this->docLiq('05', ['ventaGravada' => '100.00']),
            $this->docLiq('03', ['ventaGravada' => '50.00'], invalidado: true),
        ]);
        $this->assertCumpleEsquema($d);
        $this->assertSame('350.00', $d->total('subTotalVentas')->toFixed(2));
        $this->assertSame('45.50', $d->total('tributo')->toFixed(2));
        $this->assertEquals(-13, $d->documento()['cuerpoDocumento'][1]['ivaItem']);
    }

    public function test_cl_nota_de_credito_invalidada_suma(): void
    {
        $d = $this->cl([$this->docLiq('03', ['ventaGravada' => '200.00']), $this->docLiq('05', ['ventaGravada' => '20.00'], invalidado: true)]);
        $this->assertCumpleEsquema($d);
        $this->assertSame('220.00', $d->total('subTotalVentas')->toFixed(2));
    }

    public function test_cl_con_exentas_exportaciones_y_percepcion(): void
    {
        $d = $this->cl([
            $this->docLiq('03', ['ventaGravada' => '300.00', 'ventaExenta' => '40.00']),
            $this->docLiq('11', ['exportaciones' => '700.00']),
        ], '3.00');
        $this->assertCumpleEsquema($d);
        $this->assertSame('1040.00', $d->total('subTotalVentas')->toFixed(2));
        $this->assertSame('1082.00', $d->total('total')->toFixed(2));  // 1040 + 39 + 3
    }

    public function test_cl_si_las_restas_superan_las_sumas_queda_en_cero(): void
    {
        $d = $this->cl([$this->docLiq('03', ['ventaGravada' => '10.00']), $this->docLiq('05', ['ventaGravada' => '30.00'])]);
        $this->assertCumpleEsquema($d);
        $this->assertSame('0.00', $d->total('total')->toFixed(2));
    }

    // ===================== DCL (09) =====================

    private function dcl(array $liq): DteArmado
    {
        return (new ContableLiquidacionBuilder)->construir($this->emisor(),
            ['nit' => '06140101851014', 'nrc' => '765432', 'nombre' => 'RESTAURANTE AFILIADO, S.A.', 'codActividad' => '56101', 'descActividad' => 'Restaurantes', 'tipoEstablecimiento' => '02', 'correo' => 'pagos@afiliado.test'],
            ['inicio' => '2026-11-01', 'fin' => '2026-11-07', ...$liq],
            ['nombEntrega' => 'Ana Gómez', 'docuEntrega' => '012345678'],
            8, $this->fecha());
    }

    public function test_dcl_liquidacion_basica(): void
    {
        $d = $this->dcl(['valorOperaciones' => '1130.00', 'montoSujetoPercepcion' => '1000.00', 'iva' => '130.00', 'comision' => '45.20', 'porcentComision' => '4']);
        $this->assertCumpleEsquema($d);
        $this->assertSame(['1130.00', '20.00', '5.88', '1058.92'], [$d->total('subTotal')->toFixed(2), $d->total('ivaPercibido')->toFixed(2), $d->total('ivaComision')->toFixed(2), $d->total('liquidoApagar')->toFixed(2)]);
    }

    public function test_dcl_resta_los_valores_no_sujetos_del_subtotal(): void
    {
        $d = $this->dcl(['valorOperaciones' => '2000.00', 'montoSinPercepcion' => '260.00', 'descripSinPercepcion' => 'Propinas', 'montoSujetoPercepcion' => '1539.82', 'iva' => '200.18']);
        $this->assertCumpleEsquema($d);
        $this->assertSame('1740.00', $d->total('subTotal')->toFixed(2));
        $this->assertSame('30.80', $d->total('ivaPercibido')->toFixed(2));   // 1539.82 × 2 % = 30.7964
        $this->assertSame('1969.20', $d->total('liquidoApagar')->toFixed(2));
    }

    public function test_dcl_sin_comision(): void
    {
        $d = $this->dcl(['valorOperaciones' => '565.00', 'montoSujetoPercepcion' => '500.00', 'iva' => '65.00']);
        $this->assertCumpleEsquema($d);
        $this->assertSame('555.00', $d->total('liquidoApagar')->toFixed(2));
        $this->assertSame('0.00', $d->total('ivaComision')->toFixed(2));
    }

    public function test_dcl_redondea_el_iva_de_la_comision(): void
    {
        $d = $this->dcl(['valorOperaciones' => '100.00', 'montoSujetoPercepcion' => '88.50', 'iva' => '11.50', 'comision' => '3.33']);
        $this->assertCumpleEsquema($d);
        $this->assertSame('0.43', $d->total('ivaComision')->toFixed(2));   // 0.4329
        $this->assertSame('94.47', $d->total('liquidoApagar')->toFixed(2)); // 100 − 3.33 − 1.77 − 0.43
    }

    public function test_dcl_valida_el_periodo_y_los_montos(): void
    {
        try {
            $this->dcl(['inicio' => '2026-11-08', 'valorOperaciones' => '10', 'montoSujetoPercepcion' => '8', 'iva' => '1']);
            $this->fail('Debió rechazar el período');
        } catch (DteException) {
        }
        $this->expectException(DteException::class);
        $this->dcl(['valorOperaciones' => '10', 'montoSujetoPercepcion' => '0', 'iva' => '1']);
    }
}
