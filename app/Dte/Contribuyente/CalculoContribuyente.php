<?php

declare(strict_types=1);

namespace App\Dte\Contribuyente;

use App\Support\Decimal;

/** Resultado de los cálculos de un CCF/NC/ND/NR (ítems a 8 decimales, resumen a 2). */
final readonly class CalculoContribuyente
{
    /**
     * @param  list<array{linea: Linea, cantidad: Decimal, precioUni: Decimal, montoDescu: Decimal, ventaNoSuj: Decimal, ventaExenta: Decimal, ventaGravada: Decimal, noGravado: Decimal, totalIva: Decimal, ivaPerci: Decimal, ivaRete: Decimal}>  $items
     */
    public function __construct(
        public array $items,
        public Decimal $totalNoSuj,
        public Decimal $totalExenta,
        public Decimal $totalGravada,
        public Decimal $subTotalVentas,
        public Decimal $descuNoSuj,
        public Decimal $descuExenta,
        public Decimal $descuGravada,
        public Decimal $porcentajeDescuento,
        public Decimal $totalDescu,
        public Decimal $subTotal,
        public Decimal $iva,
        public Decimal $ivaPerci,
        public Decimal $ivaRete,
        public Decimal $montoTotalOperacion,
        public Decimal $totalNoGravado,
        public Decimal $totalPagar,
    ) {}
}
