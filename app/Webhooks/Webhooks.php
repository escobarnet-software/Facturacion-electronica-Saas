<?php

declare(strict_types=1);

namespace App\Webhooks;

use App\Jobs\EnviarWebhook;
use App\Models\Contingencia;
use App\Models\Dte;
use App\Models\Webhook;
use App\Models\WebhookEntrega;
use App\Tenancy\Tenancy;
use Illuminate\Support\Str;

/**
 * Dispara los webhooks de la empresa activa. Cada entrega guarda el cuerpo exacto que se firma;
 * los reintentos reenvían ese mismo cuerpo (con backoff, en la cola «webhooks»).
 */
final class Webhooks
{
    public static function dte(string $evento, Dte $dte): void
    {
        self::disparar($evento, [
            'codigo_generacion' => $dte->codigo_generacion,
            'numero_control' => $dte->numero_control,
            'tipo' => $dte->tipo,
            'estado' => $dte->estado->value,
            'sello_recibido' => $dte->sello_recibido,
            'fecha_emision' => $dte->fecha_emision->format('Y-m-d'),
            'total_pagar' => (string) $dte->getRawOriginal('total_pagar'),
        ]);
    }

    public static function contingencia(string $evento, Contingencia $c): void
    {
        self::disparar($evento, [
            'id' => $c->id,
            'ambiente' => $c->ambiente,
            'tipo' => $c->tipo,
            'inicio' => $c->inicio->toIso8601String(),
            'fin' => $c->fin?->toIso8601String(),
        ]);
    }

    /** @param array<string, mixed> $datos */
    public static function disparar(string $evento, array $datos): void
    {
        $empresaId = app(Tenancy::class)->empresaId();
        if ($empresaId === null) {
            return;
        }
        $webhooks = Webhook::query()->where('activo', true)->whereJsonContains('eventos', $evento)->get();

        foreach ($webhooks as $webhook) {
            $uuid = (string) Str::uuid();
            $entrega = WebhookEntrega::query()->create([
                'webhook_id' => $webhook->id,
                'uuid' => $uuid,
                'evento' => $evento,
                'cuerpo' => (string) json_encode(['id' => $uuid, 'evento' => $evento, 'fecha' => now()->toIso8601String(), 'datos' => $datos], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            EnviarWebhook::dispatch($empresaId, $entrega->id)->afterCommit();
        }
    }

    /** Cabecera de firma: t=<unix>,v1=<HMAC-SHA256 hex de "t.cuerpo">. */
    public static function firma(#[\SensitiveParameter] string $secreto, string $cuerpo, int $momento): string
    {
        return 't='.$momento.',v1='.hash_hmac('sha256', $momento.'.'.$cuerpo, $secreto);
    }
}
