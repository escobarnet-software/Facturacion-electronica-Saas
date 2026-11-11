<?php

namespace App\Livewire\Panel;

use App\Dte\Eventos\Plazos;
use App\Enums\EstadoDte;
use App\Models\Contingencia;
use App\Models\Dte;
use App\Models\EmpresaCredencial;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Tablero: ventas del día y del mes, documentos por estado y alertas. */
#[Layout('layouts.panel')]
#[Title('Inicio')]
class Inicio extends Component
{
    public function render(): View
    {
        $hoy = CarbonImmutable::now(Plazos::zona());
        $vigentes = [EstadoDte::Transmitido->value, EstadoDte::Observado->value, EstadoDte::Ajustado->value, EstadoDte::Transitorio->value];
        $suma = fn ($q) => (string) ($q->whereIn('estado', $vigentes)->sum('total_pagar') ?: '0');

        $porVencer = Dte::query()->whereNotNull('sello_recibido')->whereIn('estado', [EstadoDte::Transmitido->value, EstadoDte::Observado->value])
            ->where('fecha_emision', '>=', $hoy->subMonths(3)->format('Y-m-d'))->get()
            ->filter(function (Dte $d) use ($hoy) {
                $limite = (new Plazos)->limiteInvalidacion($d);

                return $limite->greaterThanOrEqualTo($hoy) && $limite->diffInDays($hoy) <= 3;
            })->count();

        return view('livewire.panel.inicio', [
            'ventasHoy' => $suma(Dte::query()->where('fecha_emision', $hoy->format('Y-m-d'))),
            'ventasMes' => $suma(Dte::query()->whereBetween('fecha_emision', [$hoy->startOfMonth()->format('Y-m-d'), $hoy->format('Y-m-d')])),
            'porEstado' => Dte::query()->selectRaw('estado, count(*) AS n')->groupBy('estado')->pluck('n', 'estado')->all(),
            'rechazados' => Dte::query()->where('estado', EstadoDte::Rechazado->value)->count(),
            'contingencia' => Contingencia::query()->where('estado', Contingencia::ACTIVA)->exists(),
            'credencialesBloqueadas' => EmpresaCredencial::query()->whereNotNull('bloqueado_at')->exists(),
            'invalidacionesPorVencer' => $porVencer,
        ]);
    }
}
