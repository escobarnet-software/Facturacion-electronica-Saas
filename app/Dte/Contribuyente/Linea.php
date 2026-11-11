<?php

declare(strict_types=1);

namespace App\Dte\Contribuyente;

use App\Support\Decimal;

/**
 * Línea de un CCF, NC, ND o NR. El precio va SIN IVA.
 *
 * Fórmula base (ESPECIFICACION §6.2): venta = cantidad × precioUni − montoDescu, a 8 decimales.
 */
final readonly class Linea
{
    public Decimal $cantidad;

    public Decimal $precioUnitario;

    public Decimal $montoDescu;

    /** Solo para TipoVenta::NoAfecta: monto del cargo (+) o abono (−). */
    public Decimal $noGravado;

    public function __construct(
        public string $descripcion,
        string|int|Decimal $cantidad,
        string|int|Decimal $precioUnitario,
        public TipoVenta $tipoVenta = TipoVenta::Gravada,
        string|int|Decimal $montoDescu = '0',
        public int $tipoItem = 1,               // CAT-011: 1 bienes, 2 servicios, 3 ambos, 4 otros tributos
        public int $uniMedida = 59,             // CAT-014: 59 = unidad
        public ?string $codigo = null,
        public ?string $codTributo = null,      // tipoItem 4: tributo del CAT-015 sección 2 (A8, 57, 90, D4, D5…)
        public ?string $numeroDocumento = null, // NC/ND: código de generación del documento que ajusta
        string|int|Decimal $noGravado = '0',
    ) {
        $this->cantidad = Decimal::of($cantidad);
        $this->precioUnitario = Decimal::of($precioUnitario);
        $this->montoDescu = Decimal::of($montoDescu);
        $this->noGravado = Decimal::of($noGravado);
    }

    public static function gravada(string $descripcion, string|int|Decimal $cantidad, string|int|Decimal $precio, string|int|Decimal $descuento = '0', ?string $codigo = null, ?string $numeroDocumento = null, int $tipoItem = 1, int $uniMedida = 59): self
    {
        return new self($descripcion, $cantidad, $precio, TipoVenta::Gravada, $descuento, $tipoItem, $uniMedida, $codigo, null, $numeroDocumento);
    }

    public static function exenta(string $descripcion, string|int|Decimal $cantidad, string|int|Decimal $precio, string|int|Decimal $descuento = '0', ?string $codigo = null, ?string $numeroDocumento = null): self
    {
        return new self($descripcion, $cantidad, $precio, TipoVenta::Exenta, $descuento, 1, 59, $codigo, null, $numeroDocumento);
    }

    public static function noSujeta(string $descripcion, string|int|Decimal $cantidad, string|int|Decimal $precio, string|int|Decimal $descuento = '0', ?string $codigo = null, ?string $numeroDocumento = null): self
    {
        return new self($descripcion, $cantidad, $precio, TipoVenta::NoSujeta, $descuento, 1, 59, $codigo, null, $numeroDocumento);
    }

    /** «Monto no afecto» (Manual del Sistema de Facturación): cargo (+) o abono (−) fuera de la base imponible. */
    public static function noAfecta(string $descripcion, string|int|Decimal $monto, ?string $numeroDocumento = null): self
    {
        return new self($descripcion, '1', '0', TipoVenta::NoAfecta, '0', 1, 59, null, null, $numeroDocumento, $monto);
    }

    /** «Otros tipos de impuesto»: ítem tipo 4 con un tributo del CAT-015, sección 2. Lleva IVA. */
    public static function otroImpuesto(string $descripcion, string $codTributo, string|int|Decimal $monto, ?string $numeroDocumento = null): self
    {
        return new self($descripcion, '1', $monto, TipoVenta::Gravada, '0', 4, 99, null, $codTributo, $numeroDocumento);
    }
}
