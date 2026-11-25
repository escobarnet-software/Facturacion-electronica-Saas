<?php

declare(strict_types=1);

namespace App\Plataforma;

use App\Models\HaciendaMetrica;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Throwable;

/**
 * Registra latencia y errores de cada llamada a Hacienda (para el estado del superadmin).
 * Escucha los eventos del cliente HTTP: no toca el código de transmisión.
 */
final class MetricasHacienda
{
    public function respuesta(ResponseReceived $e): void
    {
        $tiempo = $e->response->transferStats?->getTransferTime();
        $codigo = null;
        try {
            $json = $e->response->json();
            $codigo = is_array($json) && isset($json['codigoMsg']) ? (string) $json['codigoMsg'] : null;
        } catch (Throwable) {
        }
        $this->guardar($e->request, $e->response->status(), $tiempo === null ? null : (int) round($tiempo * 1000), $codigo);
    }

    public function falla(ConnectionFailed $e): void
    {
        $this->guardar($e->request, null, null, null);
    }

    private function guardar(Request $request, ?int $status, ?int $ms, ?string $codigo): void
    {
        foreach ((array) config('dte.api.base_url') as $ambiente => $base) {
            if (str_starts_with($request->url(), (string) $base)) {
                $endpoint = substr((string) parse_url($request->url(), PHP_URL_PATH), 0, 60);
                // El código de lote va en la ruta: se agrupa como un solo endpoint.
                $endpoint = (string) preg_replace('#/consultadtelote/.*$#', '/consultadtelote', $endpoint);
                try {
                    HaciendaMetrica::query()->create(['ambiente' => (string) $ambiente, 'endpoint' => $endpoint, 'status' => $status, 'duracion_ms' => $ms, 'codigo_msg' => $codigo ? substr($codigo, 0, 10) : null]);
                } catch (Throwable) {
                    // Una métrica nunca debe interrumpir la transmisión.
                }

                return;
            }
        }
    }
}
