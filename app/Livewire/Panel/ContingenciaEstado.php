<?php

namespace App\Livewire\Panel;

use App\Enums\EstadoDte;
use App\Models\Contingencia;
use App\Models\Dte;
use App\Models\Evento;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Contingencia: estado actual, documentos pendientes, evento y borrador del Informe Técnico. */
#[Layout('layouts.panel')]
#[Title('Contingencia')]
class ContingenciaEstado extends Component
{
    public function mount(): void
    {
        Gate::authorize('ver-contingencia');
    }

    public function render(): View
    {
        $actual = Contingencia::query()->where('estado', '!=', Contingencia::COMPLETADA)->latest('id')->first();

        return view('livewire.panel.contingencia', [
            'actual' => $actual,
            'evento' => $actual?->evento_id ? Evento::query()->find($actual->evento_id) : null,
            'pendientes' => $actual ? Dte::query()->where('contingencia_id', $actual->id)->whereIn('estado', [EstadoDte::Transitorio->value, EstadoDte::Enviado->value])->get() : collect(),
            'historial' => Contingencia::query()->latest('id')->limit(10)->get(),
        ]);
    }
}
