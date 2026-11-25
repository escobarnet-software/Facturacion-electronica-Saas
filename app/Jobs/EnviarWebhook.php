<?php

namespace App\Jobs;

use App\Jobs\Middleware\ConEmpresa;
use App\Models\WebhookEntrega;
use App\Tenancy\Tenancy;
use App\Webhooks\DestinoWebhook;
use App\Webhooks\Webhooks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Envía una entrega de webhook firmada. Si falla, se reintenta con backoff creciente. */
class EnviarWebhook implements ShouldQueue
{
    use Queueable;

    /** 1 envío + 6 reintentos (≈ 9 horas en total). */
    public int $tries = 7;

    public int $timeout = 30;

    public function __construct(public readonly int $empresaId, public readonly int $entregaId)
    {
        $this->onQueue('webhooks');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ConEmpresa($this->empresaId)];
    }

    /**
     * Segundos de espera antes de cada reintento.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600, 14400];
    }

    public function handle(): void
    {
        $entrega = WebhookEntrega::query()->with('webhook')->find($this->entregaId);
        if ($entrega === null || $entrega->estado === 'entregado' || ! $entrega->webhook->activo) {
            return;
        }

        if (($motivo = DestinoWebhook::rechazo($entrega->webhook->url)) !== null) {
            $entrega->forceFill(['estado' => 'fallido', 'ultimo_error' => $motivo])->save();

            return;
        }
        $momento = now()->getTimestamp();
        $entrega->increment('intentos');
        try {
            $respuesta = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()
                ->withHeaders([
                    'User-Agent' => 'EscobarNet-DTE-Webhooks/1.0',
                    'X-DTE-Evento' => $entrega->evento,
                    'X-DTE-Entrega' => $entrega->uuid,
                    'X-DTE-Firma' => Webhooks::firma($entrega->webhook->secreto, $entrega->cuerpo, $momento),
                ])
                ->withBody($entrega->cuerpo, 'application/json')
                ->post($entrega->webhook->url);
        } catch (Throwable $e) {
            $entrega->forceFill(['ultimo_status' => null, 'ultimo_error' => mb_substr('Sin conexión: '.class_basename($e), 0, 500)])->save();
            throw new RuntimeException("Webhook {$entrega->uuid}: sin conexión con el destino.");
        }

        if (! $respuesta->successful()) {
            $entrega->forceFill(['ultimo_status' => $respuesta->status(), 'ultimo_error' => 'El destino respondió HTTP '.$respuesta->status()])->save();
            throw new RuntimeException("Webhook {$entrega->uuid}: el destino respondió HTTP {$respuesta->status()}.");
        }

        $entrega->forceFill(['estado' => 'entregado', 'ultimo_status' => $respuesta->status(), 'ultimo_error' => null, 'entregado_at' => now()])->save();
    }

    public function failed(?Throwable $e): void
    {
        // failed() corre fuera del middleware: se fija la empresa explícitamente.
        app(Tenancy::class)->ejecutarComo($this->empresaId, function () {
            WebhookEntrega::query()->whereKey($this->entregaId)->where('estado', 'pendiente')->update(['estado' => 'fallido']);
        });
    }
}
