<?php

declare(strict_types=1);

namespace App\Dte\Modelos;

use App\Dte\Support\Decimal;

/**
 * Una línea de la venta tal como la manda la caja.
 *
 * En una Factura (01) el precio unitario YA INCLUYE el IVA.
 * Cantidades y precios se reciben como TEXTO ("0.75"), nunca como float.
 */
final readonly class LineaVenta
{
    public Decimal $cantidad;
    public Decimal $precioUnitario;

    // Los descuentos (por ítem y globales) se agregan en un paso posterior,
    // siguiendo la sección XVIII del Manual Funcional v2.0.
    public function __construct(
        public string $descripcion,
        string|int|Decimal $cantidad,
        string|int|Decimal $precioUnitario,
        public int $uniMedida = 59,     // catálogo CAT-014; 59 = Unidad
        public int $tipoItem = 1,       // catálogo CAT-011; 1 = Bienes, 2 = Servicios
        public ?string $codigo = null,  // código interno del producto
    ) {
        $this->cantidad = Decimal::of($cantidad);
        $this->precioUnitario = Decimal::of($precioUnitario);
    }
}
