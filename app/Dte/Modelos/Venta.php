<?php

declare(strict_types=1);

namespace App\Dte\Modelos;

use DateTimeImmutable;

/**
 * La venta completa que el motor convierte en DTE.
 */
final readonly class Venta
{
    /**
     * @param  LineaVenta[]  $lineas
     * @param  Pago[]  $pagos  vacío = un solo pago en efectivo por el total
     */
    public function __construct(
        public array $lineas,
        public Receptor $receptor,
        public int $correlativo,                 // siguiente número de la caja (lo da la base de datos)
        public DateTimeImmutable $fechaHora,     // en zona America/El_Salvador
        public array $pagos = [],
        public int $condicionOperacion = 1,      // catálogo CAT-016; 1 = Contado
        public ?string $observaciones = null,
    ) {}
}
