<?php

declare(strict_types=1);

namespace App\Plataforma;

use App\Mail\AvisoConsumo;
use App\Models\Bitacora;
use App\Models\ConsumoMensual;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Plan;
use App\Models\Suscripcion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Consumo mensual de documentos frente al plan.
 *
 * Regla de oro (ESPECIFICACION §19.4): NUNCA se bloquea la emisión por el límite del plan; emitir es una
 * obligación legal del cliente. Se cuenta, se avisa al 80 % y al 100 %, y el excedente se factura.
 */
final class Consumo
{
    /** Cuenta un documento recién creado (atómico: dos cajas a la vez nunca pierden un conteo). */
    public static function registrar(Dte $dte): void
    {
        $periodo = $dte->fecha_emision->format('Y-m');
        $limite = self::plan()?->documentos_mes;

        $fila = DB::selectOne(
            'INSERT INTO consumos_mensuales (empresa_id, periodo, documentos, limite, created_at, updated_at)
             VALUES (?, ?, 1, ?, now(), now())
             ON CONFLICT (empresa_id, periodo) DO UPDATE SET documentos = consumos_mensuales.documentos + 1, limite = EXCLUDED.limite, updated_at = now()
             RETURNING id, documentos',
            [$dte->empresa_id, $periodo, $limite],
        );

        if ($limite !== null && $limite > 0) {
            self::avisar((int) $fila->id, (int) $fila->documentos, $limite, $periodo);
        }
    }

    /** Plan vigente de la empresa activa (o null si no tiene suscripción). */
    public static function plan(): ?Plan
    {
        return Suscripcion::query()->with('plan')->where('estado', 'activa')->latest('inicio')->first()?->plan;
    }

    public static function delMes(?string $periodo = null): ?ConsumoMensual
    {
        return ConsumoMensual::query()->where('periodo', $periodo ?? now((string) config('dte.zona_horaria'))->format('Y-m'))->first();
    }

    private static function avisar(int $consumoId, int $documentos, int $limite, string $periodo): void
    {
        foreach ([100 => 'aviso_100_at', 80 => 'aviso_80_at'] as $porcentaje => $columna) {
            // Avisa una sola vez por umbral: solo quien logra marcar la columna envía el aviso.
            if ($documentos * 100 < $limite * $porcentaje) {
                continue;
            }
            $marcado = ConsumoMensual::query()->whereKey($consumoId)->whereNull($columna)->update([$columna => now()]);
            if ($marcado === 1) {
                $consumo = ConsumoMensual::query()->findOrFail($consumoId);
                Bitacora::registrar("plan.consumo_{$porcentaje}", $consumo, ['documentos' => $documentos, 'limite' => $limite, 'periodo' => $periodo]);
                $correo = Empresa::query()->whereKey($consumo->empresa_id)->value('correo');
                if (is_string($correo) && $correo !== '') {
                    Mail::to($correo)->queue(new AvisoConsumo($porcentaje, $documentos, $limite, $periodo));
                }
            }

            return;
        }
    }
}
