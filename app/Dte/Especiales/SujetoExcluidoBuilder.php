<?php

declare(strict_types=1);

namespace App\Dte\Especiales;

use App\Dte\Catalogos\Catalogo;
use App\Dte\Excepciones\DteException;
use App\Dte\Modelos\Emisor;
use App\Dte\Modelos\Pago;
use App\Dte\Support\NumeroALetras;
use App\Support\Decimal;
use App\Support\DteJson;
use DateTimeImmutable;

/**
 * Factura de Sujeto Excluido (14, v2): documenta una COMPRA a una persona no inscrita en IVA.
 * El emisor es el comprador; el sujeto excluido (vendedor) es el receptor. No lleva IVA.
 *
 *   compra = cantidad × precioUni − montoDescu (8 decimales)
 *   subTotal = totalCompra − descuento global;  totalPagar = subTotal − reteRenta
 *
 * TODO(normativa): porcentaje y aplicabilidad de la retención de renta (ESPECIFICACION §6.7 dice
 * 10 % sobre servicios, marcado [VERIFICAR]). Por ahora: solo si quien emite lo pide, sobre las
 * líneas de servicios (tipoItem 2), con el porcentaje indicado (10 % por defecto).
 */
final class SujetoExcluidoBuilder
{
    use ArmaDte;

    /**
     * @param  array{tipoDocumento: string, numDocumento: string, nombre: string, codActividad?: string|null, descActividad?: string|null, departamento: string, municipio: string, distrito: string, complemento: string, telefono?: string|null, correo?: string|null}  $sujeto
     * @param  list<array{descripcion: string, cantidad: string, precio: string, descuento?: string, tipoItem?: int, uniMedida?: int, codigo?: string|null}>  $lineas
     * @param  list<Pago>  $pagos
     */
    public function construir(
        Emisor $emisor,
        array $sujeto,
        array $lineas,
        int $correlativo,
        DateTimeImmutable $fechaHora,
        string $ambiente = '00',
        string $descuentoGlobal = '0',
        bool $retenerRenta = false,
        string $porcentajeRenta = '10',
        array $pagos = [],
        int $condicionOperacion = 1,
        ?string $observaciones = null,
        ?string $codigoGeneracion = null,
    ): DteArmado {
        if ($lineas === [] || count($lineas) > 2000) {
            throw new DteException('La factura de sujeto excluido debe tener entre 1 y 2,000 líneas.');
        }
        if (! Catalogo::existe(Catalogo::CONDICION_OPERACION, $condicionOperacion)) {
            throw new DteException("Condición de operación inválida: {$condicionOperacion} (CAT-016).");
        }

        $cuerpo = [];
        $suma = Decimal::zero();
        $sumaServicios = Decimal::zero();
        $descItems = Decimal::zero();
        foreach (array_values($lineas) as $i => $l) {
            $n = $i + 1;
            $this->validarDescripcion($l['descripcion'], $n);
            $cantidad = Decimal::of($l['cantidad'])->round(8);
            $precio = Decimal::of($l['precio'])->round(8);
            $descuento = Decimal::of($l['descuento'] ?? '0')->round(8);
            $tipoItem = $l['tipoItem'] ?? 1;
            if (! $cantidad->isPositive() || $precio->isNegative() || $descuento->isNegative()) {
                throw new DteException("Línea {$n}: cantidad, precio o descuento inválidos.");
            }
            $compra = $cantidad->mul($precio)->sub($descuento)->round(8);
            if ($compra->isNegative()) {
                throw new DteException("Línea {$n}: el descuento es mayor que el valor de la línea.");
            }
            $suma = $suma->add($compra);
            $descItems = $descItems->add($descuento);
            if ($tipoItem === 2) {
                $sumaServicios = $sumaServicios->add($compra);
            }
            $cuerpo[] = [
                'numItem' => $n, 'tipoItem' => $tipoItem, 'cantidad' => $cantidad, 'codigo' => $l['codigo'] ?? null,
                'uniMedida' => $l['uniMedida'] ?? 59, 'descripcion' => $l['descripcion'], 'precioUni' => $precio,
                'montoDescu' => $descuento, 'compra' => $compra,
            ];
        }

        $totalCompra = $suma->round(2);
        $descu = Decimal::of($descuentoGlobal)->round(2);
        if ($descu->isNegative() || $descu->compareTo($totalCompra) > 0) {
            throw new DteException('El descuento global no puede ser negativo ni mayor que el total de compras.');
        }
        $subTotal = $totalCompra->sub($descu);
        $reteRenta = $retenerRenta ? $sumaServicios->round(2)->mul($porcentajeRenta)->div(100, 2) : Decimal::zero();
        $totalPagar = $subTotal->sub($reteRenta);

        [$identificacion, $numeroControl, $codigo] = $this->identificacion('14', 2, $ambiente, $emisor, $correlativo, $fechaHora, $codigoGeneracion);

        $documento = [
            'identificacion' => $identificacion,
            'emisor' => [
                'nit' => $emisor->nit, 'nrc' => $emisor->nrc, 'nombre' => $emisor->nombre,
                'codActividad' => $emisor->codActividad, 'descActividad' => $emisor->descActividad,
                'direccion' => $this->direccionEmisor($emisor), 'telefono' => $emisor->telefono,
                'codEstable' => $emisor->codEstable, 'codPuntoVenta' => $emisor->codPuntoVenta, 'correo' => $emisor->correo,
            ],
            'receptor' => [
                'tipoDocumento' => $sujeto['tipoDocumento'], 'numDocumento' => $sujeto['numDocumento'], 'nombre' => $sujeto['nombre'],
                'codActividad' => $sujeto['codActividad'] ?? null, 'descActividad' => $sujeto['descActividad'] ?? null,
                'direccion' => ['departamento' => $sujeto['departamento'], 'municipio' => $sujeto['municipio'], 'distrito' => $sujeto['distrito'], 'complemento' => $sujeto['complemento']],
                'telefono' => $sujeto['telefono'] ?? null, 'correo' => $sujeto['correo'] ?? null,
            ],
            'cuerpoDocumento' => $cuerpo,
            'resumen' => [
                'totalCompra' => $totalCompra,
                'descu' => $descu,
                'totalDescu' => $descItems->add($descu)->round(2),
                'subTotal' => $subTotal,
                'reteRenta' => $reteRenta,
                'totalPagar' => $totalPagar,
                'totalLetras' => NumeroALetras::convertir($totalPagar),
                'condicionOperacion' => $condicionOperacion,
                'pagos' => $this->pagosQueSuman($pagos, $totalPagar),
                'observaciones' => $observaciones,
            ],
            'apendice' => null,
        ];

        return new DteArmado('14', 2, $codigo, $numeroControl, [
            'totalCompra' => $totalCompra, 'subTotal' => $subTotal, 'reteRenta' => $reteRenta, 'totalPagar' => $totalPagar,
        ], DteJson::encode($documento));
    }
}
