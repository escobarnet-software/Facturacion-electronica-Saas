<?php

declare(strict_types=1);

namespace App\Plataforma;

use App\Models\Bitacora;
use App\Models\Cobro;

/** Pago confirmado a mano por el superadmin (transferencia o depósito). */
final class CobroManual implements PasarelaCobro
{
    public function nombre(): string
    {
        return 'manual';
    }

    public function registrarPago(Cobro $cobro, string $referencia): void
    {
        $cobro->forceFill(['estado' => 'pagado', 'pasarela' => $this->nombre(), 'referencia_pago' => $referencia, 'pagado_at' => now()])->save();
        Bitacora::registrar('cobro.pagado', $cobro, ['periodo' => $cobro->periodo, 'referencia' => $referencia]);
    }
}
