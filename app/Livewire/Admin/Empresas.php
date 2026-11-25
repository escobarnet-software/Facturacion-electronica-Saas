<?php

namespace App\Livewire\Admin;

use App\Enums\Rol;
use App\Models\AccesoSoporte;
use App\Models\Bitacora;
use App\Models\ConsumoMensual;
use App\Models\Empresa;
use App\Models\Plan;
use App\Models\Suscripcion;
use App\Models\Usuario;
use App\Tenancy\Tenancy;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Superadmin: empresas (alta, suspensión, plan y consumo). Nunca muestra credenciales.
 *
 * Las consultas usan Tenancy::sinAislamiento() porque el superadmin opera la PLATAFORMA (todas las
 * empresas); solo se leen columnas de negocio no sensibles (nada de empresa_credenciales).
 */
#[Layout('layouts.admin')]
#[Title('Empresas')]
class Empresas extends Component
{
    /** @var array{nit: string, nrc: string, razon_social: string, correo: string, plan_id: string, dueno_email: string, dueno_nombre: string} */
    public array $nueva = ['nit' => '', 'nrc' => '', 'razon_social' => '', 'correo' => '', 'plan_id' => '', 'dueno_email' => '', 'dueno_nombre' => ''];

    public ?string $mensaje = null;

    public ?string $claveTemporal = null;

    public function mount(): void
    {
        Gate::authorize('superadmin');
    }

    public function crear(Tenancy $tenancy): void
    {
        Gate::authorize('superadmin');
        $d = $this->validate([
            'nueva.nit' => ['required', 'regex:/^(\d{9}|\d{14})$/'],
            'nueva.nrc' => ['nullable', 'regex:/^\d{1,8}$/'],
            'nueva.razon_social' => 'required|string|max:250',
            'nueva.correo' => 'required|email|max:100',
            'nueva.plan_id' => 'required|integer',
            'nueva.dueno_email' => 'required|email|max:255',
            'nueva.dueno_nombre' => 'required|string|max:255',
        ], ['nueva.nit.regex' => 'El NIT debe tener 9 o 14 dígitos, sin guiones.'])['nueva'];

        $this->claveTemporal = null;
        $tenancy->sinAislamiento(function () use ($d, $tenancy) {
            if (Empresa::query()->where('nit', $d['nit'])->exists()) {
                $this->addError('nueva.nit', 'Ya existe una empresa con ese NIT.');

                return;
            }
            $plan = Plan::query()->where('activo', true)->findOrFail((int) $d['plan_id']);
            $empresa = Empresa::query()->create(['nit' => $d['nit'], 'nrc' => $d['nrc'] ?: null, 'razon_social' => $d['razon_social'], 'correo' => $d['correo']]);

            $tenancy->ejecutarComo($empresa->id, function () use ($empresa, $plan, $d) {
                Suscripcion::query()->create(['plan_id' => $plan->id, 'estado' => 'activa', 'inicio' => now()->toDateString()]);
                $dueno = Usuario::query()->where('email', $d['dueno_email'])->first();
                if ($dueno === null) {
                    // Contraseña temporal mostrada UNA vez al superadmin; el dueño activa 2FA al entrar.
                    // TODO(paso-14): reemplazar por invitación por correo con enlace de un solo uso.
                    $this->claveTemporal = Str::password(16);
                    $dueno = Usuario::query()->forceCreate(['nombre' => $d['dueno_nombre'], 'email' => $d['dueno_email'], 'password' => $this->claveTemporal]);
                }
                $empresa->usuarios()->attach($dueno->id, ['rol' => Rol::Dueno->value]);
                Bitacora::registrar('empresa.alta', $empresa, ['plan' => $plan->codigo, 'por' => auth()->user()?->email]);
            });
            $this->mensaje = "Empresa {$empresa->razon_social} creada con el plan {$plan->nombre}.";
            $this->reset('nueva');
        });
    }

    public function cambiarEstado(int $empresaId, string $estado, Tenancy $tenancy): void
    {
        Gate::authorize('superadmin');
        abort_unless(in_array($estado, ['activa', 'suspendida'], true), 422);
        $tenancy->ejecutarComo($empresaId, function () use ($empresaId, $estado) {
            Empresa::query()->whereKey($empresaId)->update(['estado' => $estado]);
            Bitacora::registrar($estado === 'suspendida' ? 'empresa.suspendida' : 'empresa.reactivada', null, ['por' => auth()->user()?->email]);
        });
        $this->mensaje = $estado === 'suspendida' ? 'Empresa suspendida.' : 'Empresa reactivada.';
    }

    public function cambiarPlan(int $empresaId, int $planId, Tenancy $tenancy): void
    {
        Gate::authorize('superadmin');
        $plan = Plan::query()->where('activo', true)->findOrFail($planId);
        $tenancy->ejecutarComo($empresaId, function () use ($plan) {
            Suscripcion::query()->where('estado', 'activa')->update(['estado' => 'cancelada', 'fin' => now()->toDateString()]);
            Suscripcion::query()->create(['plan_id' => $plan->id, 'estado' => 'activa', 'inicio' => now()->toDateString()]);
            Bitacora::registrar('plan.cambiado', null, ['plan' => $plan->codigo, 'por' => auth()->user()?->email]);
        });
        $this->mensaje = "Plan cambiado a {$plan->nombre}.";
    }

    public function render(Tenancy $tenancy): View
    {
        $periodo = now((string) config('dte.zona_horaria'))->format('Y-m');
        $filas = $tenancy->sinAislamiento(function () use ($periodo) {
            $planes = Suscripcion::query()->with('plan')->where('estado', 'activa')->get()->keyBy('empresa_id');
            $consumos = ConsumoMensual::query()->where('periodo', $periodo)->pluck('documentos', 'empresa_id');
            $soporte = AccesoSoporte::query()->whereNull('revocado_at')->where('expira_at', '>', now())->pluck('expira_at', 'empresa_id');

            return Empresa::query()->orderBy('razon_social')->get(['id', 'nit', 'razon_social', 'estado', 'ambiente'])
                ->map(fn (Empresa $e) => [
                    'id' => $e->id, 'nit' => $e->nit, 'razon_social' => $e->razon_social, 'estado' => $e->estado, 'ambiente' => $e->ambiente,
                    'plan' => $planes->get($e->id)?->plan, 'documentos' => (int) ($consumos[$e->id] ?? 0), 'soporte' => $soporte->has($e->id),
                ]);
        });

        return view('livewire.admin.empresas', ['filas' => $filas, 'planes' => Plan::query()->where('activo', true)->orderBy('precio_mensual')->get()]);
    }
}
