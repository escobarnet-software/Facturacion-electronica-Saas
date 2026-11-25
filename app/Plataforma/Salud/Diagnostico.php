<?php

declare(strict_types=1);

namespace App\Plataforma\Salud;

use App\Models\HaciendaMetrica;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Revisión rápida de los componentes (para /salud y para las alertas). Nunca devuelve datos de clientes.
 * Hacienda se evalúa en forma PASIVA con las métricas de las llamadas reales (y del sondeo, si está activo).
 */
final class Diagnostico
{
    /** @return array{estado: string, componentes: array<string, array{ok: bool, detalle: string}>} */
    public function revisar(): array
    {
        $c = [
            'base_de_datos' => $this->probar(fn () => DB::select('select 1') !== [] ? 'responde' : 'sin respuesta'),
            'redis' => $this->probar(fn () => Redis::connection()->ping() ? 'responde' : 'sin respuesta'),
            'firmador' => $this->firmador(),
            'cola_transmision' => $this->cola('transmision'),
            'hacienda' => $this->hacienda(),
        ];
        $criticos = ['base_de_datos', 'redis'];
        $estado = collect($criticos)->every(fn ($k) => $c[$k]['ok']) ? (collect($c)->every(fn ($x) => $x['ok']) ? 'ok' : 'degradado') : 'caido';

        return ['estado' => $estado, 'componentes' => $c];
    }

    /** @return array{ok: bool, detalle: string} */
    public function firmador(): array
    {
        $partes = parse_url((string) config('dte.firmador.url'));
        $host = (string) ($partes['host'] ?? '');
        $puerto = (int) ($partes['port'] ?? (($partes['scheme'] ?? 'http') === 'https' ? 443 : 80));
        $conexion = @fsockopen($host, $puerto, $errno, $errstr, 2.0);
        if ($conexion === false) {
            return ['ok' => false, 'detalle' => 'no acepta conexiones'];
        }
        fclose($conexion);

        return ['ok' => true, 'detalle' => 'acepta conexiones'];
    }

    /** @return array{ok: bool, detalle: string} */
    public function hacienda(int $minutos = 5): array
    {
        $fila = HaciendaMetrica::query()->where('created_at', '>=', now()->subMinutes($minutos))
            ->selectRaw('count(*) AS total, count(*) FILTER (WHERE status IS NULL OR status >= 500) AS errores')->first();
        $total = (int) ($fila->total ?? 0);
        $errores = (int) ($fila->errores ?? 0);
        if ($total === 0) {
            return ['ok' => true, 'detalle' => "sin llamadas en {$minutos} min"];
        }
        $ok = ! ($total >= 3 && $errores * 2 >= $total);

        return ['ok' => $ok, 'detalle' => "{$errores} error(es) de {$total} llamada(s) en {$minutos} min"];
    }

    /** @return array{ok: bool, detalle: string} */
    public function cola(string $nombre): array
    {
        try {
            $tamano = Queue::size($nombre);
        } catch (Throwable) {
            return ['ok' => false, 'detalle' => 'no se pudo leer'];
        }
        $max = (int) config('plataforma.alertas.cola_maxima');

        return ['ok' => $tamano <= $max, 'detalle' => "{$tamano} en espera"];
    }

    /**
     * @param  callable(): string  $prueba
     * @return array{ok: bool, detalle: string}
     */
    private function probar(callable $prueba): array
    {
        try {
            $detalle = $prueba();

            return ['ok' => $detalle === 'responde', 'detalle' => $detalle];
        } catch (Throwable) {
            return ['ok' => false, 'detalle' => 'sin conexión'];
        }
    }
}
