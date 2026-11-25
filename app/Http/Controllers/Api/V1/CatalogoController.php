<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CatValor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Catálogos oficiales de Hacienda (CAT-001 a CAT-033), comunes a todas las empresas. */
final class CatalogoController
{
    public function __invoke(Request $request, string $catalogo): JsonResponse
    {
        $codigo = preg_match('/^\d{1,3}$/', $catalogo) ? 'CAT-'.str_pad($catalogo, 3, '0', STR_PAD_LEFT) : strtoupper($catalogo);
        $valores = CatValor::query()->where('catalogo', $codigo)->where('vigente', true)
            ->when($request->query('padre'), fn ($q, $padre) => $q->where('padre', (string) $padre))
            ->orderBy('padre')->orderBy('codigo')->get(['codigo', 'valor', 'padre']);
        abort_if($valores->isEmpty(), 404, 'No existe ese catálogo o no tiene valores vigentes.');

        return response()->json(['catalogo' => $codigo, 'data' => $valores]);
    }
}
