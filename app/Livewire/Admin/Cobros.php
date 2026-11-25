<?php

namespace App\Livewire\Admin;

use App\Models\Cobro;
use App\Models\Empresa;
use App\Plataforma\CobroManual;
use App\Tenancy\Tenancy;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Superadmin: cobros mensuales y registro manual de pagos (pasarela pendiente, decisión 3). */
#[Layout('layouts.admin')]
#[Title('Cobros')]
class Cobros extends Component
{
    #[Url]
    public string $periodo = '';

    /** @var array<int, string> */
    public array $referencias = [];

    public function mount(): void
    {
        Gate::authorize('superadmin');
        if (! preg_match('/^\d{4}-\d{2}$/', $this->periodo)) {
            $this->periodo = now((string) config('dte.zona_horaria'))->subMonthNoOverflow()->format('Y-m');
        }
    }

    public function registrarPago(int $empresaId, int $cobroId, Tenancy $tenancy, CobroManual $pasarela): void
    {
        Gate::authorize('superadmin');
        $referencia = trim($this->referencias[$cobroId] ?? '');
        if ($referencia === '') {
            $this->addError("referencias.{$cobroId}", 'Escriba la referencia del pago.');

            return;
        }
        $tenancy->ejecutarComo($empresaId, fn () => $pasarela->registrarPago(Cobro::query()->where('estado', 'pendiente')->findOrFail($cobroId), $referencia));
    }

    public function render(Tenancy $tenancy): View
    {
        // Lista de todos los cobros del período: vista de plataforma (sin aislamiento, sin datos sensibles).
        $cobros = $tenancy->sinAislamiento(fn () => Cobro::query()->where('periodo', $this->periodo)->orderBy('empresa_id')->get());
        $empresas = $tenancy->sinAislamiento(fn () => Empresa::query()->whereIn('id', $cobros->pluck('empresa_id'))->pluck('razon_social', 'id'));

        return view('livewire.admin.cobros', compact('cobros', 'empresas'));
    }
}
