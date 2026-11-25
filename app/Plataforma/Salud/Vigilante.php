<?php

declare(strict_types=1);

namespace App\Plataforma\Salud;

use App\Mail\AlertaPlataforma;
use App\Models\Contingencia;
use App\Models\Usuario;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Vigila la plataforma cada minuto y avisa a los superadmin (correo y log). Cada alerta se repite
 * como mucho cada `plataforma.alertas.silencio_minutos`, para no inundar el correo.
 */
final class Vigilante
{
    public function __construct(private readonly Diagnostico $diagnostico, private readonly Tenancy $tenancy) {}

    /** @return list<string> claves de las alertas enviadas */
    public function vigilar(): array
    {
        $alertas = [];
        $d = $this->diagnostico->revisar()['componentes'];

        foreach (['base_de_datos' => 'La base de datos no responde', 'redis' => 'Redis no responde', 'firmador' => 'El firmador no acepta conexiones', 'cola_transmision' => 'La cola de transmisión está atrasada', 'hacienda' => 'Hacienda está fallando'] as $clave => $titulo) {
            if (! $d[$clave]['ok']) {
                $alertas[$clave] = "{$titulo}: {$d[$clave]['detalle']}.";
            }
        }

        $fallidos = DB::table('failed_jobs')->where('failed_at', '>=', now()->subMinutes(15))->count();
        if ($fallidos > 0) {
            $alertas['jobs_fallidos'] = "{$fallidos} job(s) fallaron en los últimos 15 minutos (revise Horizon).";
        }

        // Solo el conteo de contingencias activas (dato operativo, sin documentos de los clientes).
        $contingencias = $this->tenancy->sinAislamiento(fn () => Contingencia::query()->where('estado', Contingencia::ACTIVA)->count());
        if ($contingencias > 0) {
            $alertas['contingencias'] = "{$contingencias} empresa(s) en contingencia.";
        }

        $enviadas = [];
        foreach ($alertas as $clave => $mensaje) {
            if (Cache::add("alerta:{$clave}", true, now()->addMinutes((int) config('plataforma.alertas.silencio_minutos')))) {
                Log::warning("[alerta] {$mensaje}");
                $correos = Usuario::query()->where('es_superadmin', true)->pluck('email')->all();
                if ($correos !== []) {
                    Mail::to($correos)->queue(new AlertaPlataforma($mensaje));
                }
                $enviadas[] = $clave;
            }
        }

        return $enviadas;
    }
}
