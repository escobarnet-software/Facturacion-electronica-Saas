<?php

namespace App\Livewire\Admin;

use App\Models\Bitacora;
use App\Models\Plan;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Superadmin: planes configurables (nunca fijos en el código). Precios sin IVA, como texto exacto. */
#[Layout('layouts.admin')]
#[Title('Planes')]
class Planes extends Component
{
    /** @var array<string, string|bool> */
    public array $plan = ['codigo' => '', 'nombre' => '', 'precio_mensual' => '', 'documentos_mes' => '', 'precio_documento_adicional' => '0', 'max_establecimientos' => '', 'max_usuarios' => '', 'incluye_api' => false, 'incluye_pos' => true];

    public function mount(): void
    {
        Gate::authorize('superadmin');
    }

    public function guardar(): void
    {
        Gate::authorize('superadmin');
        $d = $this->validate([
            'plan.codigo' => 'required|alpha_dash|max:30|unique:planes,codigo',
            'plan.nombre' => 'required|string|max:100',
            'plan.precio_mensual' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'plan.documentos_mes' => 'nullable|integer|min:1',
            'plan.precio_documento_adicional' => ['required', 'regex:/^\d{1,9}(\.\d{1,8})?$/'],
            'plan.max_establecimientos' => 'nullable|integer|min:1',
            'plan.max_usuarios' => 'nullable|integer|min:1',
            'plan.incluye_api' => 'boolean',
            'plan.incluye_pos' => 'boolean',
        ])['plan'];
        $plan = Plan::query()->create(array_map(fn ($v) => $v === '' ? null : $v, $d));
        Bitacora::registrar('plan.creado', $plan, ['codigo' => $plan->codigo]);
        $this->reset('plan');
    }

    public function alternar(int $id): void
    {
        Gate::authorize('superadmin');
        $plan = Plan::query()->findOrFail($id);
        $plan->forceFill(['activo' => ! $plan->activo])->save();
    }

    public function render(): View
    {
        return view('livewire.admin.planes', ['planes' => Plan::query()->orderBy('precio_mensual')->get()]);
    }
}
