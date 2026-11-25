<?php

declare(strict_types=1);

namespace App\Plataforma;

use App\Models\EmpresaUsuario;
use App\Models\Establecimiento;

/**
 * Límites de configuración del plan (establecimientos, usuarios, API). Se aplican al CONFIGURAR,
 * nunca al emitir: una venta siempre se puede documentar.
 */
final class Limites
{
    /** Mensaje de error si no se puede agregar otro establecimiento (o null si se puede). */
    public static function establecimiento(): ?string
    {
        $max = Consumo::plan()?->max_establecimientos;

        return $max !== null && Establecimiento::query()->count() >= $max
            ? "Su plan permite {$max} establecimiento(s). Cambie de plan para agregar más."
            : null;
    }

    public static function usuario(): ?string
    {
        $max = Consumo::plan()?->max_usuarios;

        return $max !== null && EmpresaUsuario::query()->count() >= $max
            ? "Su plan permite {$max} usuario(s). Cambie de plan para agregar más."
            : null;
    }

    public static function api(): ?string
    {
        $plan = Consumo::plan();

        return $plan !== null && ! $plan->incluye_api ? 'Su plan no incluye la API. Cambie de plan para conectar su POS o ERP.' : null;
    }
}
