<?php

namespace App\Livewire\Panel;

use App\Models\AccesoSoporte;
use App\Models\Bitacora;
use App\Models\Cobro;
use App\Plataforma\Consumo;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Plan, consumo y cobros de la empresa; permisos temporales para el soporte de Escobar Net. */
#[Layout('layouts.panel')]
#[Title('Plan y soporte')]
class Suscripcion extends Component
{
    public int $horas = 24;

    public string $motivo = '';

    public function mount(): void
    {
        Gate::authorize('administrar-empresa');
    }

    public function otorgar(): void
    {
        Gate::authorize('administrar-empresa');
        $this->validate([
            'horas' => 'required|integer|min:1|max:'.(int) config('plataforma.soporte_horas_max'),
            'motivo' => 'required|string|max:250',
        ]);
        $acceso = AccesoSoporte::query()->create(['otorgado_por' => (int) auth()->id(), 'motivo' => $this->motivo, 'expira_at' => now()->addHours($this->horas)]);
        Bitacora::registrar('soporte.permiso_otorgado', $acceso, ['horas' => $this->horas, 'motivo' => $this->motivo]);
        $this->reset('motivo');
    }

    public function revocar(int $id): void
    {
        Gate::authorize('administrar-empresa');
        $acceso = AccesoSoporte::query()->whereNull('revocado_at')->findOrFail($id);
        $acceso->forceFill(['revocado_at' => now()])->save();
        Bitacora::registrar('soporte.permiso_revocado', $acceso);
    }

    public function render(): View
    {
        return view('livewire.panel.suscripcion', [
            'plan' => Consumo::plan(),
            'consumo' => Consumo::delMes(),
            'cobros' => Cobro::query()->orderByDesc('periodo')->limit(12)->get(),
            'accesos' => AccesoSoporte::query()->orderByDesc('id')->limit(10)->get(),
        ]);
    }
}
