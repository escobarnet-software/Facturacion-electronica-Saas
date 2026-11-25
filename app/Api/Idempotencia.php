<?php

declare(strict_types=1);

namespace App\Api;

use App\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cabecera Idempotency-Key: la misma clave nunca genera dos documentos.
 *
 *  - Primera vez: se reserva la clave (fila única por empresa) y se ejecuta la operación.
 *  - Repetición con el mismo cuerpo: se devuelve la respuesta guardada (Idempotent-Replayed: true).
 *  - Misma clave con otro cuerpo: 422. Mientras la primera sigue en curso: 409.
 *  - Si la operación falla con error del servidor, se libera la clave para reintentar.
 */
final class Idempotencia
{
    public function __construct(private readonly Tenancy $tenancy) {}

    /** @param Closure(): JsonResponse $operacion */
    public function ejecutar(Request $request, Closure $operacion): JsonResponse
    {
        $clave = $request->header('Idempotency-Key');
        if ($clave === null || $clave === '') {
            return $operacion();
        }
        if (strlen($clave) > 255) {
            return response()->json(['message' => 'La cabecera Idempotency-Key no puede tener más de 255 caracteres.'], 422);
        }

        $empresaId = (int) $this->tenancy->empresaId();
        $huella = hash('sha256', $request->method().' '.$request->path()."\n".$request->getContent());
        $reservada = DB::table('api_idempotencia')->insertOrIgnore([
            'empresa_id' => $empresaId, 'clave' => $clave, 'huella' => $huella, 'estado' => 'procesando',
            'created_at' => now(), 'updated_at' => now(),
        ]) === 1;

        if (! $reservada) {
            $previa = DB::table('api_idempotencia')->where('empresa_id', $empresaId)->where('clave', $clave)->first();
            if ($previa === null) {
                return response()->json(['message' => 'No se pudo reservar la clave de idempotencia; intente de nuevo.'], 409);
            }
            if ($previa->huella !== $huella) {
                return response()->json(['message' => 'Esa clave de idempotencia ya se usó con otra solicitud.'], 422);
            }
            if ($previa->estado !== 'completado') {
                return response()->json(['message' => 'La solicitud con esa clave aún se está procesando.'], 409);
            }

            return response()->json(json_decode((string) $previa->respuesta, true), (int) $previa->status, ['Idempotent-Replayed' => 'true']);
        }

        try {
            $respuesta = $operacion();
        } catch (Throwable $e) {
            $this->liberar($empresaId, $clave);
            throw $e;
        }

        if ($respuesta->getStatusCode() >= 500) {
            $this->liberar($empresaId, $clave);
        } else {
            DB::table('api_idempotencia')->where('empresa_id', $empresaId)->where('clave', $clave)->update([
                'estado' => 'completado', 'status' => $respuesta->getStatusCode(),
                'respuesta' => $respuesta->getContent(), 'updated_at' => now(),
            ]);
        }

        return $respuesta;
    }

    private function liberar(int $empresaId, string $clave): void
    {
        DB::table('api_idempotencia')->where('empresa_id', $empresaId)->where('clave', $clave)->delete();
    }
}
