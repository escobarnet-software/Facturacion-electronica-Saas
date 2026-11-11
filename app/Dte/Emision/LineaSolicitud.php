<?php

declare(strict_types=1);

namespace App\Dte\Emision;

/**
 * Línea de una venta tal como la captura el panel, el POS o la API.
 * El precio va SIN IVA; para la Factura la plataforma calcula el precio con IVA.
 */
final readonly class LineaSolicitud
{
    public function __construct(
        public string $descripcion,
        public string $cantidad,
        public string $precioSinIva,
        public string $descuento = '0',
        public string $tipoVenta = 'gravada',
        public int $tipoItem = 1,
        public int $uniMedida = 59,
        public ?string $codigo = null,
    ) {}
}
