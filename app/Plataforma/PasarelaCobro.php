<?php

declare(strict_types=1);

namespace App\Plataforma;

use App\Models\Cobro;

/**
 * Pasarela para cobrar las suscripciones.
 *
 * TODO(decision-3): la pasarela de cobro es una decisión pendiente del PLAN. Mientras tanto
 * solo existe el registro manual (transferencia o depósito confirmado por el superadmin).
 */
interface PasarelaCobro
{
    public function nombre(): string;

    public function registrarPago(Cobro $cobro, string $referencia): void;
}
