<?php

declare(strict_types=1);

namespace App\Dte\Contribuyente;

use App\Dte\Excepciones\DteException;
use App\Support\Decimal;

/**
 * Cálculos de los documentos entre contribuyentes (precio SIN IVA).
 *
 * Reglas (ESPECIFICACION §6, Manual Funcional v2.0 secciones XVIII y XXI):
 *   - Ítem: venta = cantidad × precioUni − montoDescu, a 8 decimales (HALF_UP).
 *   - Resumen a 2 decimales (HALF_UP), calculado desde los ítems ya redondeados.
 *   - Descuento global = porcentaje × total de cada tipo de venta.
 *   - IVA 13 % = (totalGravada − descuGravada) × 0.13.
 *   - Retención / percepción del 1 % sobre la misma base gravada.
 *   - totalDescu = descuentos por ítem + descuentos globales (informativo).
 *   - montoTotalOperacion = subTotal + IVA.
 *   - totalPagar = montoTotalOperacion + ivaPerci − ivaRete + totalNoGravado.
 */
final class CalculadoraContribuyente
{
    public const IVA = '0.13';

    public const RETENCION_PERCEPCION = '0.01';

    private const ITEM = 8;

    private const RESUMEN = 2;

    public function calcular(DocumentoContribuyente $doc, bool $impuestosPorItem = false): CalculoContribuyente
    {
        if ($doc->lineas === []) {
            throw new DteException('El documento debe tener al menos una línea.');
        }
        if (count($doc->lineas) > 2000) {
            throw new DteException('Un documento puede tener como máximo 2,000 líneas.');
        }

        $items = [];
        $suma = ['noSuj' => Decimal::zero(), 'exenta' => Decimal::zero(), 'gravada' => Decimal::zero()];
        $sumaDescItems = Decimal::zero();
        $sumaNoGravado = Decimal::zero();
        $sumaIvaItems = Decimal::zero();

        foreach (array_values($doc->lineas) as $i => $linea) {
            $n = $i + 1;
            $cantidad = $linea->cantidad->round(self::ITEM);
            $precio = $linea->precioUnitario->round(self::ITEM);
            $descuento = $linea->montoDescu->round(self::ITEM);

            if (! $cantidad->isPositive()) {
                throw new DteException("Línea {$n}: la cantidad debe ser mayor que cero.");
            }
            if ($precio->isNegative() || $descuento->isNegative()) {
                throw new DteException("Línea {$n}: el precio y el descuento no pueden ser negativos.");
            }

            $venta = $cantidad->mul($precio)->sub($descuento)->round(self::ITEM);
            if ($venta->isNegative()) {
                throw new DteException("Línea {$n}: el descuento es mayor que el valor de la línea.");
            }

            $cero = Decimal::zero();
            [$noSuj, $exenta, $gravada, $noGravado] = match ($linea->tipoVenta) {
                TipoVenta::NoSujeta => [$venta, $cero, $cero, $cero],
                TipoVenta::Exenta => [$cero, $venta, $cero, $cero],
                TipoVenta::Gravada => [$cero, $cero, $venta, $cero],
                TipoVenta::NoAfecta => [$cero, $cero, $cero, $linea->noGravado->round(self::ITEM)],
            };

            $totalIva = $gravada->mul(self::IVA)->round(self::ITEM);
            $items[] = [
                'linea' => $linea,
                'cantidad' => $cantidad,
                'precioUni' => $precio,
                'montoDescu' => $descuento,
                'ventaNoSuj' => $noSuj,
                'ventaExenta' => $exenta,
                'ventaGravada' => $gravada,
                'noGravado' => $noGravado,
                'totalIva' => $totalIva,
                'ivaPerci' => $doc->percibirIva1 ? $gravada->mul(self::RETENCION_PERCEPCION)->round(self::ITEM) : $cero,
                'ivaRete' => $doc->retenerIva1 ? $gravada->mul(self::RETENCION_PERCEPCION)->round(self::ITEM) : $cero,
            ];

            $suma['noSuj'] = $suma['noSuj']->add($noSuj);
            $suma['exenta'] = $suma['exenta']->add($exenta);
            $suma['gravada'] = $suma['gravada']->add($gravada);
            $sumaDescItems = $sumaDescItems->add($descuento);
            $sumaNoGravado = $sumaNoGravado->add($noGravado);
            $sumaIvaItems = $sumaIvaItems->add($totalIva);
        }

        $totalNoSuj = $suma['noSuj']->round(self::RESUMEN);
        $totalExenta = $suma['exenta']->round(self::RESUMEN);
        $totalGravada = $suma['gravada']->round(self::RESUMEN);
        $subTotalVentas = $totalNoSuj->add($totalExenta)->add($totalGravada);

        $porcentaje = ($doc->porcentajeDescuento ?? Decimal::zero())->round(self::RESUMEN);
        if ($porcentaje->isNegative() || $porcentaje->compareTo('100') > 0) {
            throw new DteException('El porcentaje de descuento global debe estar entre 0 y 100.');
        }
        // Monto explícito si se indicó; si no, el porcentaje sobre el total de ese tipo de venta.
        $global = function (?Decimal $monto, Decimal $total, string $tipo) use ($porcentaje): Decimal {
            $descuento = $monto !== null
                ? $monto->round(self::RESUMEN)
                : $total->mul($porcentaje)->div(100, self::RESUMEN + 6)->round(self::RESUMEN);
            if ($descuento->isNegative() || $descuento->compareTo($total) > 0) {
                throw new DteException("El descuento global a ventas {$tipo} no puede ser negativo ni mayor que esas ventas.");
            }

            return $descuento;
        };
        $descuNoSuj = $global($doc->descuentosGlobales['noSujeta'], $totalNoSuj, 'no sujetas');
        $descuExenta = $global($doc->descuentosGlobales['exenta'], $totalExenta, 'exentas');
        $descuGravada = $global($doc->descuentosGlobales['gravada'], $totalGravada, 'gravadas');

        $totalDescu = $sumaDescItems->add($descuNoSuj)->add($descuExenta)->add($descuGravada)->round(self::RESUMEN);
        $subTotal = $subTotalVentas->sub($descuNoSuj)->sub($descuExenta)->sub($descuGravada);

        $baseGravada = $totalGravada->sub($descuGravada);
        $iva = $baseGravada->mul(self::IVA)->round(self::RESUMEN);
        $ivaPerci = $doc->percibirIva1 ? $baseGravada->mul(self::RETENCION_PERCEPCION)->round(self::RESUMEN) : Decimal::zero();
        $ivaRete = $doc->retenerIva1 ? $baseGravada->mul(self::RETENCION_PERCEPCION)->round(self::RESUMEN) : Decimal::zero();

        $montoTotalOperacion = $subTotal->add($iva);
        $totalNoGravado = $sumaNoGravado->round(self::RESUMEN);
        $totalPagar = $montoTotalOperacion->add($ivaPerci)->sub($ivaRete)->add($totalNoGravado);

        if ($totalPagar->isNegative()) {
            throw new DteException('El total a pagar no puede ser negativo: revise los abonos no afectos.');
        }

        // Holgura de ±0.01 entre el IVA de los ítems y el del resumen (solo aplica sin descuento global).
        if ($impuestosPorItem && $descuGravada->isZero() && $sumaIvaItems->sub($iva)->abs()->compareTo('0.01') > 0) {
            throw new DteException('El IVA de las líneas no cuadra con el IVA total (diferencia mayor a 1 centavo).');
        }

        return new CalculoContribuyente(
            items: $items,
            totalNoSuj: $totalNoSuj,
            totalExenta: $totalExenta,
            totalGravada: $totalGravada,
            subTotalVentas: $subTotalVentas,
            descuNoSuj: $descuNoSuj,
            descuExenta: $descuExenta,
            descuGravada: $descuGravada,
            porcentajeDescuento: $porcentaje,
            totalDescu: $totalDescu,
            subTotal: $subTotal,
            iva: $iva,
            ivaPerci: $ivaPerci,
            ivaRete: $ivaRete,
            montoTotalOperacion: $montoTotalOperacion,
            totalNoGravado: $totalNoGravado,
            totalPagar: $totalPagar,
        );
    }
}
