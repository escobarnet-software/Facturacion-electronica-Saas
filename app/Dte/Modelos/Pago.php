<?php

declare(strict_types=1);

namespace App\Dte\Modelos;

use App\Support\Decimal;

/** Una forma de pago (catálogo CAT-017). Si el monto es null, se usa el total a pagar. */
final readonly class Pago
{
    public ?Decimal $monto;

    public function __construct(
        public string $codigo = '01',          // 01 = Billetes y monedas
        string|int|Decimal|null $monto = null,
        public ?string $referencia = null,
        public ?string $plazo = null,          // catálogo CAT-018 (ventas a crédito)
        public ?int $periodo = null,
    ) {
        $this->monto = $monto === null ? null : Decimal::of($monto);
    }
}
