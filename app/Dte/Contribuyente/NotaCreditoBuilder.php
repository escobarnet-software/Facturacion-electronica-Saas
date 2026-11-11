<?php

declare(strict_types=1);

namespace App\Dte\Contribuyente;

/** Nota de Crédito (05, v4): disminuye un CCF (devolución, descuento posterior). */
final class NotaCreditoBuilder extends AjusteBuilder
{
    protected function tipoDte(): string
    {
        return '05';
    }
}
