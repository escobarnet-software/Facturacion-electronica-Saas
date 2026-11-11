<?php

declare(strict_types=1);

namespace App\Dte\Especiales;

use App\Dte\Catalogos\Catalogo;
use App\Dte\Contribuyente\ReceptorContribuyente;
use App\Dte\Excepciones\DteException;
use App\Dte\Modelos\Emisor;
use App\Dte\Support\NumeroALetras;
use App\Support\Decimal;
use App\Support\DteJson;
use DateTimeImmutable;

/**
 * Comprobante de Liquidación (08, v2): el comisionista (emisor) liquida al mandante (receptor)
 * las ventas que hizo por su cuenta. El cuerpo lista DOCUMENTOS, no productos.
 *
 * Reglas (Normativa v2.0, Anexo IV, campos 96–154):
 *  - Valores SIN IVA; si el documento es una Factura con ventas gravadas, se agrega el tributo 20.
 *  - Signo: F, CCF, ND y FEX anulados/invalidados se restan; las NC (y el retorno) se restan
 *    salvo que estén anulados/invalidados, en cuyo caso suman.
 *  - ivaItem: CCF/NC/ND → ventaGravada × 13 %; Factura → el IVA de su resumen (se indica).
 *  - subTotalVentas = no sujetas + exentas + gravadas + exportaciones (si resulta negativo: $0.00).
 *  - Tributo 20 del resumen = totalGravada × 13 % (consolidado del cuerpo); montoTotalOperacion = subTotalVentas + tributos.
 *  - total = montoTotalOperacion + IVA percibido liquidado.
 */
final class LiquidacionBuilder
{
    use ArmaDte;

    /**
     * @param  list<array{tipoDte: string, numeroDocumento: string, fechaEmision: string, tipoGeneracion?: int, ventaNoSuj?: string, ventaExenta?: string, ventaGravada?: string, exportaciones?: string, ivaItem?: string, invalidado?: bool, observaciones?: string|null}>  $documentos
     */
    public function construir(
        Emisor $emisor,
        ReceptorContribuyente $mandante,
        int $codDomiciliado,
        array $documentos,
        int $correlativo,
        DateTimeImmutable $fechaHora,
        string $ambiente = '00',
        string $ivaPercibido = '0',
        int $condicionOperacion = 1,
        ?string $observaciones = null,
        ?string $codigoGeneracion = null,
    ): DteArmado {
        if ($documentos === [] || count($documentos) > 500) {
            throw new DteException('El comprobante de liquidación debe tener entre 1 y 500 documentos.');
        }
        if (! Catalogo::existe(Catalogo::CONDICION_OPERACION, $condicionOperacion)) {
            throw new DteException("Condición de operación inválida: {$condicionOperacion} (CAT-016).");
        }

        $cuerpo = [];
        $s = ['noSuj' => Decimal::zero(), 'exenta' => Decimal::zero(), 'gravada' => Decimal::zero(), 'exportacion' => Decimal::zero()];
        foreach (array_values($documentos) as $i => $d) {
            $n = $i + 1;
            $tipo = $d['tipoDte'];
            if (! in_array($tipo, ['01', '03', '05', '06', '11'], true)) {
                throw new DteException("Documento {$n}: solo se liquidan Facturas, CCF, Notas de Crédito y Débito y Facturas de Exportación.");
            }
            $invalidado = $d['invalidado'] ?? false;
            $signo = ($tipo === '05') !== $invalidado ? '-1' : '1';

            $v = fn (string $campo) => Decimal::of($d[$campo] ?? '0')->round(8);
            [$noSuj, $exenta, $gravada, $expo] = [$v('ventaNoSuj'), $v('ventaExenta'), $v('ventaGravada'), $v('exportaciones')];
            foreach ([$noSuj, $exenta, $gravada, $expo] as $valor) {
                if ($valor->isNegative()) {
                    throw new DteException("Documento {$n}: indique los montos en positivo; el signo lo pone la plataforma.");
                }
            }
            if ($tipo === '01') {
                if (! $gravada->isZero() && ! isset($d['ivaItem'])) {
                    throw new DteException("Documento {$n}: para una Factura indique el IVA de su resumen (ivaItem).");
                }
                $iva = $v('ivaItem');
            } else {
                $iva = $gravada->mul('0.13')->round(8);
            }

            [$noSuj, $exenta, $gravada, $expo, $iva] = array_map(fn (Decimal $x) => $x->mul($signo), [$noSuj, $exenta, $gravada, $expo, $iva]);
            $s['noSuj'] = $s['noSuj']->add($noSuj);
            $s['exenta'] = $s['exenta']->add($exenta);
            $s['gravada'] = $s['gravada']->add($gravada);
            $s['exportacion'] = $s['exportacion']->add($expo);

            $cuerpo[] = [
                'numItem' => $n, 'tipoDte' => $tipo, 'tipoGeneracion' => $d['tipoGeneracion'] ?? 2,
                'numeroDocumento' => $d['numeroDocumento'], 'fechaEmision' => $d['fechaEmision'],
                'ventaNoSuj' => $noSuj, 'ventaExenta' => $exenta, 'ventaGravada' => $gravada, 'exportaciones' => $expo,
                'tributos' => $gravada->isZero() ? null : ['20'],
                'ivaItem' => $iva, 'observaciones' => $d['observaciones'] ?? null,
            ];
        }

        $totalNoSuj = $s['noSuj']->round(2);
        $totalExenta = $s['exenta']->round(2);
        $totalGravada = $s['gravada']->round(2);
        $exportacion = $s['exportacion']->round(2);
        $subTotalVentas = $totalNoSuj->add($totalExenta)->add($totalGravada)->add($exportacion);
        if ($subTotalVentas->isNegative()) {
            $subTotalVentas = Decimal::zero();
        }
        $tributo = $totalGravada->mul('0.13')->round(2);
        if ($tributo->isNegative()) {
            $tributo = Decimal::zero();
        }
        $montoTotal = $subTotalVentas->add($tributo);
        $ivaPerci = Decimal::of($ivaPercibido)->round(2);
        $total = $montoTotal->add($ivaPerci);
        if ($total->isNegative()) {
            $total = Decimal::zero();
        }

        [$identificacion, $numeroControl, $codigo] = $this->identificacion('08', 2, $ambiente, $emisor, $correlativo, $fechaHora, $codigoGeneracion, ['fusion' => null], admiteContingencia: false);

        $documento = [
            'identificacion' => $identificacion,
            'emisor' => [
                'nit' => $emisor->nit, 'nrc' => $emisor->nrc, 'nombre' => $emisor->nombre,
                'codActividad' => $emisor->codActividad, 'descActividad' => $emisor->descActividad,
                'nombreComercial' => $emisor->nombreComercial, 'direccion' => $this->direccionEmisor($emisor),
                'telefono' => $emisor->telefono, 'correo' => $emisor->correo,
                'codEstable' => $emisor->codEstable, 'codPuntoVenta' => $emisor->codPuntoVenta,
            ],
            'receptor' => [
                'tipoDocumento' => $mandante->tipoDocumento, 'numDocumento' => $mandante->nit, 'codDomiciliado' => $codDomiciliado,
                'nrc' => $mandante->nrc, 'nombre' => $mandante->nombre, 'codActividad' => $mandante->codActividad,
                'descActividad' => $mandante->descActividad, 'nombreComercial' => $mandante->nombreComercial,
                'direccion' => $mandante->direccion(), 'telefono' => $mandante->telefono, 'correo' => $mandante->correo,
            ],
            'cuerpoDocumento' => $cuerpo,
            'resumen' => [
                'totalNoSuj' => $totalNoSuj, 'totalExenta' => $totalExenta, 'totalGravada' => $totalGravada,
                'exportacion' => $exportacion, 'subTotalVentas' => $subTotalVentas,
                'tributos' => $tributo->isZero() ? null : [['codigo' => '20', 'descripcion' => 'Impuesto al Valor Agregado 13%', 'valor' => $tributo]],
                'montoTotalOperacion' => $montoTotal, 'ivaPerci' => $ivaPerci, 'total' => $total,
                'totalLetras' => NumeroALetras::convertir($total), 'condicionOperacion' => $condicionOperacion,
                'observaciones' => $observaciones,
            ],
            'apendice' => null,
        ];

        return new DteArmado('08', 2, $codigo, $numeroControl, [
            'subTotalVentas' => $subTotalVentas, 'tributo' => $tributo, 'montoTotalOperacion' => $montoTotal, 'total' => $total,
        ], DteJson::encode($documento));
    }
}
