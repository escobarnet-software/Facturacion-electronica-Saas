<?php

namespace App\Livewire\Admin;

use App\Models\Contingencia;
use App\Models\Dte;
use App\Models\HaciendaMetrica;
use App\Tenancy\Tenancy;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Superadmin: estado de Hacienda (latencia y errores por endpoint), colas, contingencias activas
 * y métricas de la plataforma. Agregados sin aislamiento (solo conteos, sin datos de los clientes).
 */
#[Layout('layouts.admin')]
#[Title('Estado de la plataforma')]
class Estado extends Component
{
    public function mount(): void
    {
        Gate::authorize('superadmin');
    }

    public function render(Tenancy $tenancy): View
    {
        $desde = now()->subDay();
        $endpoints = HaciendaMetrica::query()->where('created_at', '>=', $desde)
            ->selectRaw('ambiente, endpoint, count(*) AS llamadas, count(*) FILTER (WHERE status IS NULL OR status >= 500) AS errores, round(avg(duracion_ms)) AS promedio_ms, max(duracion_ms) AS max_ms')
            ->groupBy('ambiente', 'endpoint')->orderBy('ambiente')->orderBy('endpoint')->get();

        $colas = [];
        foreach (['transmision', 'entrega', 'webhooks', 'default'] as $cola) {
            try {
                $colas[$cola] = Queue::size($cola);
            } catch (Throwable) {
                $colas[$cola] = null;
            }
        }

        [$contingencias, $porDia, $rechazos] = $tenancy->sinAislamiento(fn () => [
            Contingencia::query()->where('estado', Contingencia::ACTIVA)->count(),
            Dte::query()->where('fecha_emision', '>=', now((string) config('dte.zona_horaria'))->subDays(13)->toDateString())
                ->selectRaw('fecha_emision, count(*) AS total')->groupBy('fecha_emision')->orderBy('fecha_emision')->pluck('total', 'fecha_emision'),
            DB::table('dtes')->where('estado', 'rechazado')->whereNotNull('respuesta_mh')
                ->selectRaw("respuesta_mh->>'codigoMsg' AS codigo, count(*) AS total")->groupBy('codigo')->orderByDesc('total')->limit(10)->get(),
        ]);

        return view('livewire.admin.estado', compact('endpoints', 'colas', 'contingencias', 'porDia', 'rechazos'));
    }
}
