<?php

declare(strict_types=1);

namespace App\Dte\Factura;

use App\Dte\Catalogos\Catalogo;
use App\Dte\Excepciones\DteException;
use App\Dte\Modelos\Emisor;
use App\Dte\Modelos\LineaVenta;
use App\Dte\Modelos\Pago;
use App\Dte\Modelos\Receptor;
use App\Dte\Modelos\Venta;
use App\Dte\Support\CodigoGeneracion;
use App\Dte\Support\Decimal;
use App\Dte\Support\DteJson;
use App\Dte\Support\NumeroALetras;
use App\Dte\Support\NumeroControl;
use DateTimeZone;

/**
 * Arma una Factura Electrónica (tipo 01, esquema versión 2) a partir de una
 * venta simple, con todos los cálculos exactos.
 *
 * Reglas aplicadas (Manual Funcional del Sistema de Transmisión v2.0):
 *   - En la Factura el precio unitario INCLUYE el IVA.
 *   - IVA contenido por línea = ventaGravada × 13 / 113, con 8 decimales.
 *   - Montos de cada línea: hasta 8 decimales. Montos del resumen: 2 decimales.
 *   - Redondeo HALF_UP. Holgura permitida en el resumen: 1 centavo.
 *   - Consumidor final con compra menor a $25,000: no se exige identificación.
 *
 * Este primer paso cubre ventas gravadas sin descuentos ni tributos especiales
 * (el caso de la mayoría de pupuserías, tiendas y restaurantes). Ventas
 * exentas, no sujetas, descuentos y retenciones se agregan después.
 */
final class FacturaBuilder
{
    public const TIPO_DTE = '01';
    public const VERSION = 2;

    private const ESCALA_ITEM = 8;
    private const ESCALA_RESUMEN = 2;
    private const LIMITE_SIN_IDENTIFICAR = '25000';

    public function __construct(
        private readonly Emisor $emisor,
        private readonly string $ambiente = '00',
    ) {
        if (!Catalogo::existe(Catalogo::AMBIENTE, $ambiente)) {
            throw new DteException("Ambiente inválido: {$ambiente}. Use 00 (pruebas) o 01 (producción).");
        }
    }

    public function construir(Venta $venta, ?string $codigoGeneracion = null): FacturaResultado
    {
        $codigoGeneracion ??= CodigoGeneracion::nuevo();
        if (!CodigoGeneracion::esValido($codigoGeneracion)) {
            throw new DteException('Código de generación inválido: debe ser un UUID v4 en mayúsculas.');
        }

        $this->validarLineas($venta->lineas);

        // --- Cuerpo del documento ---------------------------------------
        $cuerpo = [];
        $sumaGravada = Decimal::zero();
        $sumaIvaItems = Decimal::zero();

        foreach (array_values($venta->lineas) as $i => $linea) {
            $cantidad = $linea->cantidad->round(self::ESCALA_ITEM);
            $precio = $linea->precioUnitario->round(self::ESCALA_ITEM);
            $ventaGravada = $cantidad->mul($precio)->round(self::ESCALA_ITEM);
            $ivaItem = self::ivaContenido($ventaGravada, self::ESCALA_ITEM);

            $sumaGravada = $sumaGravada->add($ventaGravada);
            $sumaIvaItems = $sumaIvaItems->add($ivaItem);

            $cuerpo[] = [
                'numItem' => $i + 1,
                'tipoItem' => $linea->tipoItem,
                'numeroDocumento' => null,
                'cantidad' => $cantidad,
                'codigo' => $linea->codigo,
                'codTributo' => null,
                'uniMedida' => $linea->uniMedida,
                'descripcion' => $linea->descripcion,
                'precioUni' => $precio,
                'montoDescu' => Decimal::zero(),
                'ventaNoSuj' => Decimal::zero(),
                'ventaExenta' => Decimal::zero(),
                'ventaGravada' => $ventaGravada,
                'tributos' => null,
                'psv' => Decimal::zero(),
                'noGravado' => Decimal::zero(),
                'ivaItem' => $ivaItem,
            ];
        }

        // --- Resumen ------------------------------------------------------
        $totalGravada = $sumaGravada->round(self::ESCALA_RESUMEN);
        $subTotalVentas = $totalGravada;            // + no sujetas + exentas (cero por ahora)
        $totalDescu = Decimal::zero();
        $subTotal = $subTotalVentas->sub($totalDescu);
        $totalIva = self::ivaContenido($subTotal, self::ESCALA_RESUMEN);
        $montoTotalOperacion = $subTotal;           // en Factura el IVA ya va incluido
        $ivaRete = Decimal::zero();
        $totalNoGravado = Decimal::zero();
        $totalPagar = $montoTotalOperacion->sub($ivaRete)->add($totalNoGravado);

        // Control interno: la suma del IVA de las líneas debe quedar dentro
        // de la holgura de 1 centavo respecto al IVA del resumen.
        if ($sumaIvaItems->sub($totalIva)->abs()->compareTo('0.01') > 0) {
            throw new DteException('El IVA de las líneas no cuadra con el IVA total (diferencia mayor a 1 centavo).');
        }

        $this->validarReceptor($venta->receptor, $totalPagar);
        $pagos = $this->construirPagos($venta->pagos, $totalPagar);

        if (!Catalogo::existe(Catalogo::CONDICION_OPERACION, $venta->condicionOperacion)) {
            throw new DteException("Condición de operación inválida: {$venta->condicionOperacion}");
        }

        $fecha = $venta->fechaHora->setTimezone(new DateTimeZone('America/El_Salvador'));
        $numeroControl = NumeroControl::generar(
            self::TIPO_DTE,
            $this->emisor->codEstable,
            $this->emisor->codPuntoVenta,
            $venta->correlativo,
        );

        $documento = [
            'identificacion' => [
                'version' => self::VERSION,
                'ambiente' => $this->ambiente,
                'tipoDte' => self::TIPO_DTE,
                'numeroControl' => $numeroControl,
                'codigoGeneracion' => $codigoGeneracion,
                'tipoModelo' => 1,          // previo (transmisión normal)
                'tipoOperacion' => 1,       // normal
                'tipoContingencia' => null,
                'motivoContin' => null,
                'fecEmi' => $fecha->format('Y-m-d'),
                'horEmi' => $fecha->format('H:i:s'),
                'tipoMoneda' => 'USD',
            ],
            'documentoRelacionado' => null,
            'emisor' => $this->emisorComoArreglo(),
            'receptor' => $this->receptorComoArreglo($venta->receptor),
            'otrosDocumentos' => null,
            'ventaTercero' => null,
            'cuerpoDocumento' => $cuerpo,
            'resumen' => [
                'totalNoSuj' => Decimal::zero(),
                'totalExenta' => Decimal::zero(),
                'totalGravada' => $totalGravada,
                'subTotalVentas' => $subTotalVentas,
                'descuNoSuj' => Decimal::zero(),
                'descuExenta' => Decimal::zero(),
                'descuGravada' => Decimal::zero(),
                'porcentajeDescuento' => Decimal::zero(),
                'totalDescu' => $totalDescu,
                'tributos' => null,
                'subTotal' => $subTotal,
                'ivaRete' => $ivaRete,
                'montoTotalOperacion' => $montoTotalOperacion,
                'totalNoGravado' => $totalNoGravado,
                'totalPagar' => $totalPagar,
                'totalLetras' => NumeroALetras::convertir($totalPagar),
                'totalIva' => $totalIva,
                'saldoFavor' => Decimal::zero(),
                'condicionOperacion' => $venta->condicionOperacion,
                'pagos' => $pagos,
                'numPagoElectronico' => null,
                'observaciones' => $venta->observaciones,
            ],
            'apendice' => null,
        ];

        return new FacturaResultado(
            codigoGeneracion: $codigoGeneracion,
            numeroControl: $numeroControl,
            totalPagar: $totalPagar,
            totalIva: $totalIva,
            json: DteJson::encode($documento),
        );
    }

    /** IVA contenido en un monto que ya incluye IVA: monto × 13 / 113. */
    public static function ivaContenido(Decimal $montoConIva, int $escala): Decimal
    {
        return $montoConIva->mul(13)->div(113, $escala);
    }

    // ------------------------------------------------------------------

    /** @param LineaVenta[] $lineas */
    private function validarLineas(array $lineas): void
    {
        if (count($lineas) === 0) {
            throw new DteException('La factura debe tener al menos un producto o servicio.');
        }
        if (count($lineas) > 2000) {
            throw new DteException('Una factura puede tener como máximo 2,000 líneas.');
        }

        foreach (array_values($lineas) as $i => $linea) {
            $n = $i + 1;
            if (!$linea instanceof LineaVenta) {
                throw new DteException("La línea {$n} no es válida.");
            }
            if (!$linea->cantidad->isPositive()) {
                throw new DteException("Línea {$n}: la cantidad debe ser mayor que cero.");
            }
            if ($linea->precioUnitario->isNegative()) {
                throw new DteException("Línea {$n}: el precio no puede ser negativo.");
            }
            $largo = mb_strlen(trim($linea->descripcion));
            if ($largo < 1 || $largo > 1500) {
                throw new DteException("Línea {$n}: la descripción debe tener entre 1 y 1,500 caracteres.");
            }
            if ($linea->uniMedida < 1 || $linea->uniMedida > 99) {
                throw new DteException("Línea {$n}: unidad de medida inválida (catálogo CAT-014).");
            }
            if (!in_array($linea->tipoItem, [1, 2, 3], true)) {
                throw new DteException("Línea {$n}: tipo de ítem inválido (1 bienes, 2 servicios, 3 ambos).");
            }
            if ($linea->codigo !== null && (mb_strlen($linea->codigo) < 1 || mb_strlen($linea->codigo) > 25)) {
                throw new DteException("Línea {$n}: el código del producto debe tener entre 1 y 25 caracteres.");
            }
        }
    }

    private function validarReceptor(Receptor $receptor, Decimal $totalPagar): void
    {
        if ($totalPagar->compareTo(self::LIMITE_SIN_IDENTIFICAR) >= 0 && !$receptor->estaIdentificado()) {
            throw new DteException('Para ventas de $25,000 o más hay que identificar al cliente (tipo y número de documento, y nombre).');
        }
        if ($receptor->tipoDocumento !== null
            && !Catalogo::existe(Catalogo::TIPO_DOC_IDENTIFICACION, $receptor->tipoDocumento)) {
            throw new DteException("Tipo de documento del cliente inválido: {$receptor->tipoDocumento} (catálogo CAT-022).");
        }
        if ($receptor->correo !== null && !filter_var($receptor->correo, FILTER_VALIDATE_EMAIL)) {
            throw new DteException("El correo del cliente no es válido: {$receptor->correo}");
        }
    }

    /**
     * @param Pago[] $pagos
     * @return array<int, array<string, mixed>>
     */
    private function construirPagos(array $pagos, Decimal $totalPagar): array
    {
        if ($pagos === []) {
            $pagos = [new Pago('01')];
        }

        $resultado = [];
        $suma = Decimal::zero();
        foreach ($pagos as $pago) {
            if (!Catalogo::existe(Catalogo::FORMA_PAGO, $pago->codigo)) {
                throw new DteException("Forma de pago inválida: {$pago->codigo} (catálogo CAT-017).");
            }
            $monto = ($pago->monto ?? (count($pagos) === 1 ? $totalPagar : null));
            if ($monto === null) {
                throw new DteException('Con varias formas de pago, cada una debe indicar su monto.');
            }
            $monto = $monto->round(self::ESCALA_RESUMEN);
            $suma = $suma->add($monto);
            $resultado[] = [
                'codigo' => $pago->codigo,
                'montoPago' => $monto,
                'referencia' => $pago->referencia,
                'plazo' => $pago->plazo,
                'periodo' => $pago->periodo,
            ];
        }

        if (!$suma->equals($totalPagar)) {
            throw new DteException("Los pagos suman \${$suma->toFixed(2)} pero el total es \${$totalPagar->toFixed(2)}.");
        }

        return $resultado;
    }

    /** @return array<string, mixed> */
    private function emisorComoArreglo(): array
    {
        $e = $this->emisor;

        return [
            'nit' => $e->nit,
            'nrc' => $e->nrc,
            'nombre' => $e->nombre,
            'codActividad' => $e->codActividad,
            'descActividad' => $e->descActividad,
            'nombreComercial' => $e->nombreComercial,
            'direccion' => [
                'departamento' => $e->departamento,
                'municipio' => $e->municipio,
                'distrito' => $e->distrito,
                'complemento' => $e->complemento,
            ],
            'telefono' => $e->telefono,
            'correo' => $e->correo,
            'codEstable' => $e->codEstable,
            'codPuntoVenta' => $e->codPuntoVenta,
        ];
    }

    /** @return array<string, mixed> */
    private function receptorComoArreglo(Receptor $r): array
    {
        return [
            'tipoDocumento' => $r->tipoDocumento,
            'numDocumento' => $r->numDocumento,
            'nrc' => $r->nrc,
            'nombre' => $r->nombre,
            'codActividad' => $r->codActividad,
            'descActividad' => $r->descActividad,
            'direccion' => $r->tieneDireccion() ? [
                'departamento' => $r->departamento,
                'municipio' => $r->municipio,
                'distrito' => $r->distrito,
                'complemento' => $r->complemento,
            ] : null,
            'telefono' => $r->telefono,
            'correo' => $r->correo,
        ];
    }
}
