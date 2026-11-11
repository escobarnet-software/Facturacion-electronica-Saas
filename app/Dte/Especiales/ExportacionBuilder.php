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
 * Factura de Exportación (11, esquema v3 — la Normativa dice v2, manda el esquema).
 *
 * Tasa 0 %: ventaGravada sin IVA. Orden del resumen (representación gráfica del Manual Funcional):
 *   montoTotalOperacion = totalGravada − descuGravada + seguro + flete
 *   totalPagar = montoTotalOperacion + totalNoGravado
 *
 * Tributo C3 «IVA (exportaciones) 0 %»: obligatorio en ítems con ventas gravadas y con valor $0.00 en el
 * resumen (Normativa v2.0, Anexo IV, campos 96.22 y 138.8).
 */
final class ExportacionBuilder
{
    use ArmaDte;

    public const TRIBUTO_EXPORTACION = 'C3';

    /**
     * @param  array{tipoItemExpor: int, recintoFiscal?: string|null, regimen?: string|null, tipoRegimen?: string|null}  $exportacion
     * @param  array{nombre: string, tipoDocumento: string, numDocumento: string, descActividad: string, nombreComercial?: string|null, codPais: string, nombrePais: string, complemento: string, tipoPersona: int, telefono?: string|null, correo?: string|null}  $receptor
     * @param  list<array{descripcion: string, cantidad: string, precio: string, descuento?: string, tipoItem?: int, uniMedida?: int, codigo?: string|null, noGravado?: string}>  $lineas
     * @param  list<Pago>  $pagos
     */
    public function construir(
        Emisor $emisor,
        array $exportacion,
        array $receptor,
        array $lineas,
        int $correlativo,
        DateTimeImmutable $fechaHora,
        string $ambiente = '00',
        string $porcentajeDescuento = '0',
        string $seguro = '0',
        string $flete = '0',
        ?string $codIncoterms = null,
        ?string $descIncoterms = null,
        array $pagos = [],
        int $condicionOperacion = 1,
        ?string $observaciones = null,
        ?string $codigoGeneracion = null,
    ): DteArmado {
        if ($lineas === [] || count($lineas) > 2000) {
            throw new DteException('La factura de exportación debe tener entre 1 y 2,000 líneas.');
        }
        $tipoItemExpor = $exportacion['tipoItemExpor'];
        if (! in_array($tipoItemExpor, [1, 2, 3], true)) {
            throw new DteException('Tipo de ítem a exportar inválido: 1 bienes, 2 servicios, 3 ambos.');
        }
        $recinto = $exportacion['recintoFiscal'] ?? null;
        $regimen = $exportacion['regimen'] ?? null;
        if ($tipoItemExpor !== 2 && ($recinto === null || $regimen === null)) {
            throw new DteException('Para exportar bienes hay que indicar el recinto fiscal (CAT-027) y el régimen (CAT-028).');
        }
        if (! in_array($receptor['tipoPersona'], [1, 2], true)) {
            throw new DteException('Tipo de persona del cliente inválido (CAT-029: 1 natural, 2 jurídica).');
        }
        if (! Catalogo::existe(Catalogo::CONDICION_OPERACION, $condicionOperacion)) {
            throw new DteException("Condición de operación inválida: {$condicionOperacion} (CAT-016).");
        }

        $cuerpo = [];
        $suma = Decimal::zero();
        $descItems = Decimal::zero();
        $noGravado = Decimal::zero();
        foreach (array_values($lineas) as $i => $l) {
            $n = $i + 1;
            $this->validarDescripcion($l['descripcion'], $n);
            $cantidad = Decimal::of($l['cantidad'])->round(8);
            $precio = Decimal::of($l['precio'])->round(8);
            $descuento = Decimal::of($l['descuento'] ?? '0')->round(8);
            if (! $cantidad->isPositive() || $precio->isNegative() || $descuento->isNegative()) {
                throw new DteException("Línea {$n}: cantidad, precio o descuento inválidos.");
            }
            $venta = $cantidad->mul($precio)->sub($descuento)->round(8);
            if ($venta->isNegative()) {
                throw new DteException("Línea {$n}: el descuento es mayor que el valor de la línea.");
            }
            $ng = Decimal::of($l['noGravado'] ?? '0')->round(8);
            $suma = $suma->add($venta);
            $descItems = $descItems->add($descuento);
            $noGravado = $noGravado->add($ng);
            $cuerpo[] = [
                'numItem' => $n, 'cantidad' => $cantidad, 'codigo' => $l['codigo'] ?? null, 'uniMedida' => $l['uniMedida'] ?? 59,
                'descripcion' => $l['descripcion'], 'precioUni' => $precio, 'montoDescu' => $descuento, 'ventaGravada' => $venta,
                'tributos' => $venta->isZero() ? null : [self::TRIBUTO_EXPORTACION], 'noGravado' => $ng,
                'tipoItem' => $l['tipoItem'] ?? 1, 'numeroDocumento' => null, 'codTributo' => null,
            ];
        }

        $totalGravada = $suma->round(2);
        $porcentaje = Decimal::of($porcentajeDescuento)->round(2);
        if ($porcentaje->isNegative() || $porcentaje->compareTo('100') > 0) {
            throw new DteException('El porcentaje de descuento global debe estar entre 0 y 100.');
        }
        $descuGravada = $totalGravada->mul($porcentaje)->div(100, 2);
        $seguroD = Decimal::of($seguro)->round(2);
        $fleteD = Decimal::of($flete)->round(2);
        if ($seguroD->isNegative() || $fleteD->isNegative()) {
            throw new DteException('El seguro y el flete no pueden ser negativos.');
        }
        $montoTotal = $totalGravada->sub($descuGravada)->add($seguroD)->add($fleteD);
        $totalNoGravado = $noGravado->round(2);
        $totalPagar = $montoTotal->add($totalNoGravado);
        if ($totalPagar->isNegative()) {
            throw new DteException('El total a pagar no puede ser negativo.');
        }

        [$identificacion, $numeroControl, $codigo] = $this->identificacion('11', 3, $ambiente, $emisor, $correlativo, $fechaHora, $codigoGeneracion);

        $documento = [
            'identificacion' => $identificacion,
            'documentoRelacionado' => null,
            'emisor' => [
                'nit' => $emisor->nit, 'nrc' => $emisor->nrc, 'nombre' => $emisor->nombre,
                'codActividad' => $emisor->codActividad, 'descActividad' => $emisor->descActividad,
                'nombreComercial' => $emisor->nombreComercial, 'direccion' => $this->direccionEmisor($emisor),
                'telefono' => $emisor->telefono, 'correo' => $emisor->correo,
                'codEstable' => $emisor->codEstable, 'codPuntoVenta' => $emisor->codPuntoVenta,
                'tipoItemExpor' => $tipoItemExpor,
                'recintoFiscal' => $tipoItemExpor === 2 ? null : $recinto,
                'tipoRegimen' => $tipoItemExpor === 2 ? null : ($exportacion['tipoRegimen'] ?? null),
                'regimen' => $tipoItemExpor === 2 ? null : $regimen,
            ],
            'receptor' => [
                'nombre' => $receptor['nombre'], 'tipoDocumento' => $receptor['tipoDocumento'], 'numDocumento' => $receptor['numDocumento'],
                'descActividad' => $receptor['descActividad'], 'nombreComercial' => $receptor['nombreComercial'] ?? null,
                'codPais' => $receptor['codPais'], 'nombrePais' => $receptor['nombrePais'], 'complemento' => $receptor['complemento'],
                'tipoPersona' => $receptor['tipoPersona'], 'telefono' => $receptor['telefono'] ?? null, 'correo' => $receptor['correo'] ?? null,
            ],
            'otrosDocumentos' => null,
            'ventaTercero' => null,
            'compraTercero' => null,
            'cuerpoDocumento' => $cuerpo,
            'resumen' => [
                'totalGravada' => $totalGravada,
                'descuGravada' => $descuGravada,
                'porcentajeDescuento' => $porcentaje,
                'totalDescu' => $descItems->add($descuGravada)->round(2),
                'seguro' => $seguroD,
                'flete' => $fleteD,
                'tributos' => $totalGravada->isZero() ? null : [['codigo' => self::TRIBUTO_EXPORTACION, 'descripcion' => 'Impuesto al Valor Agregado (exportaciones) 0%', 'valor' => Decimal::zero()]],
                'montoTotalOperacion' => $montoTotal,
                'totalNoGravado' => $totalNoGravado,
                'totalNoOnerosas' => Decimal::zero(),
                'totalPagar' => $totalPagar,
                'totalLetras' => NumeroALetras::convertir($totalPagar),
                'saldoFavor' => Decimal::zero(),
                'condicionOperacion' => $condicionOperacion,
                'pagos' => $this->pagosQueSuman($pagos, $totalPagar),
                'codIncoterms' => $codIncoterms,
                'descIncoterms' => $descIncoterms,
                'numPagoElectronico' => null,
                'observaciones' => $observaciones,
            ],
            'apendice' => null,
        ];

        return new DteArmado('11', 3, $codigo, $numeroControl, [
            'totalGravada' => $totalGravada, 'descuGravada' => $descuGravada, 'montoTotalOperacion' => $montoTotal, 'totalPagar' => $totalPagar,
        ], DteJson::encode($documento));
    }
}
