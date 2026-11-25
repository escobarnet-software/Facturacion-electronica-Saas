<?php

namespace App\Http\Controllers;

use App\Plataforma\Salud\Diagnostico;
use Illuminate\Http\JsonResponse;

/** Healthcheck para el balanceador y el monitoreo externo: estado por componente, sin datos de clientes. */
final class SaludController
{
    public function __invoke(Diagnostico $diagnostico): JsonResponse
    {
        $r = $diagnostico->revisar();

        return response()->json($r, $r['estado'] === 'caido' ? 503 : 200);
    }
}
