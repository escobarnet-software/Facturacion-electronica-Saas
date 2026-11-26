<?php

namespace App\Livewire\Panel;

use App\Enums\Rol;
use App\Mail\AccesoEmpresa;
use App\Models\Bitacora;
use App\Models\Empresa;
use App\Models\EmpresaUsuario;
use App\Models\PuntoVenta;
use App\Models\Usuario;
use App\Plataforma\Limites;
use App\Tenancy\Tenancy;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Usuarios de la empresa (solo el dueño): invitar por correo con un rol, asignar la caja del cajero,
 * cambiar el rol y quitar el acceso. Nunca se puede quedar la empresa sin dueño.
 */
#[Layout('layouts.panel')]
#[Title('Usuarios')]
class Usuarios extends Component
{
    /** @var array{nombre: string, email: string, rol: string, punto_venta_id: string|null} */
    public array $nuevo = ['nombre' => '', 'email' => '', 'rol' => 'cajero', 'punto_venta_id' => null];

    public ?int $editando = null;

    /** @var array{rol: string, punto_venta_id: string|null} */
    public array $edicion = ['rol' => 'cajero', 'punto_venta_id' => null];

    public ?string $mensaje = null;

    public ?string $error = null;

    public function mount(): void
    {
        Gate::authorize('administrar-empresa');
    }

    public function invitar(Tenancy $tenancy): void
    {
        Gate::authorize('administrar-empresa');
        $this->mensaje = $this->error = null;
        $this->nuevo['email'] = mb_strtolower(trim($this->nuevo['email']));
        $this->nuevo['nombre'] = trim($this->nuevo['nombre']);
        $this->validate([
            'nuevo.nombre' => 'required|string|min:3|max:100',
            'nuevo.email' => 'required|string|email:rfc|max:255',
            'nuevo.rol' => ['required', Rule::in(array_map(fn (Rol $r) => $r->value, Rol::cases()))],
            'nuevo.punto_venta_id' => [$this->nuevo['rol'] === Rol::Cajero->value ? 'required' : 'nullable', 'integer'],
        ], ['nuevo.punto_venta_id.required' => 'El cajero necesita una caja (punto de venta).'], ['nuevo.nombre' => 'nombre', 'nuevo.email' => 'correo', 'nuevo.rol' => 'rol']);

        if (($limite = Limites::usuario()) !== null) {
            $this->error = $limite;

            return;
        }
        $empresa = Empresa::query()->findOrFail($tenancy->empresaId());
        $pv = $this->puntoVenta($this->nuevo['rol'], $this->nuevo['punto_venta_id']);

        $usuario = Usuario::query()->where('email', $this->nuevo['email'])->first();
        if ($usuario !== null && $empresa->usuarios()->whereKey($usuario->id)->exists()) {
            $this->addError('nuevo.email', 'Esa persona ya tiene acceso a la empresa.');

            return;
        }
        if ($usuario?->es_superadmin) {
            $this->addError('nuevo.email', 'Ese correo no se puede usar.');

            return;
        }
        $cuentaNueva = $usuario === null;
        $usuario ??= Usuario::query()->forceCreate(['nombre' => $this->nuevo['nombre'], 'email' => $this->nuevo['email'], 'password' => Str::password(40)]);

        $empresa->usuarios()->attach($usuario->id, ['rol' => $this->nuevo['rol'], 'punto_venta_id' => $pv]);
        $this->enviarAcceso($empresa, $usuario, Rol::from($this->nuevo['rol']));
        Bitacora::registrar('usuario.invitado', $usuario, ['rol' => $this->nuevo['rol'], 'cuenta_nueva' => $cuentaNueva]);

        $this->mensaje = $cuentaNueva
            ? "Se envió la invitación a {$usuario->email}. Tiene 72 horas para crear su contraseña."
            : "{$usuario->email} ya tenía cuenta: se le dio acceso y se le avisó por correo.";
        $this->reset('nuevo');
    }

    public function editar(int $usuarioId): void
    {
        Gate::authorize('administrar-empresa');
        $m = EmpresaUsuario::query()->where('usuario_id', $usuarioId)->firstOrFail();
        $this->editando = $usuarioId;
        $this->edicion = ['rol' => $m->rol, 'punto_venta_id' => $m->punto_venta_id ? (string) $m->punto_venta_id : null];
    }

    public function guardarRol(): void
    {
        Gate::authorize('administrar-empresa');
        $this->mensaje = $this->error = null;
        $this->validate([
            'edicion.rol' => ['required', Rule::in(array_map(fn (Rol $r) => $r->value, Rol::cases()))],
            'edicion.punto_venta_id' => [$this->edicion['rol'] === Rol::Cajero->value ? 'required' : 'nullable', 'integer'],
        ], ['edicion.punto_venta_id.required' => 'El cajero necesita una caja (punto de venta).']);
        $m = EmpresaUsuario::query()->where('usuario_id', (int) $this->editando)->firstOrFail();

        if ($m->rol === Rol::Dueno->value && $this->edicion['rol'] !== Rol::Dueno->value && $this->duenos() <= 1) {
            $this->error = 'La empresa debe tener al menos un dueño: asigne otro dueño antes de cambiar este rol.';

            return;
        }
        $antes = $m->rol;
        $m->forceFill(['rol' => $this->edicion['rol'], 'punto_venta_id' => $this->puntoVenta($this->edicion['rol'], $this->edicion['punto_venta_id'])])->save();
        Bitacora::registrar('usuario.rol_cambiado', Usuario::query()->find($m->usuario_id), ['de' => $antes, 'a' => $m->rol]);
        $this->editando = null;
        $this->mensaje = 'Rol actualizado.';
    }

    public function quitar(int $usuarioId): void
    {
        Gate::authorize('administrar-empresa');
        $this->mensaje = $this->error = null;
        if ($usuarioId === auth()->id()) {
            $this->error = 'No puede quitarse su propio acceso.';

            return;
        }
        $m = EmpresaUsuario::query()->where('usuario_id', $usuarioId)->firstOrFail();
        if ($m->rol === Rol::Dueno->value && $this->duenos() <= 1) {
            $this->error = 'La empresa debe tener al menos un dueño.';

            return;
        }
        $usuario = Usuario::query()->find($usuarioId);
        $m->delete();
        Bitacora::registrar('usuario.acceso_quitado', $usuario, ['rol' => $m->rol]);
        $this->mensaje = 'Se quitó el acceso a '.($usuario->email ?? 'la persona').'.';
    }

    public function reenviar(int $usuarioId, Tenancy $tenancy): void
    {
        Gate::authorize('administrar-empresa');
        $m = EmpresaUsuario::query()->where('usuario_id', $usuarioId)->firstOrFail();
        $usuario = Usuario::query()->findOrFail($usuarioId);
        $this->enviarAcceso(Empresa::query()->findOrFail($tenancy->empresaId()), $usuario, Rol::from($m->rol));
        Bitacora::registrar('usuario.invitacion_reenviada', $usuario);
        $this->mensaje = "Se reenvió el correo a {$usuario->email}.";
    }

    public function render(Tenancy $tenancy): View
    {
        $empresa = Empresa::query()->findOrFail($tenancy->empresaId());

        return view('livewire.panel.usuarios', [
            'miembros' => $empresa->usuarios()->where('es_superadmin', false)->orderBy('nombre')->get(),
            'puntosVenta' => PuntoVenta::query()->with('establecimiento')->orderBy('id')->get(),
            'roles' => collect(Rol::cases())->mapWithKeys(fn (Rol $r) => [$r->value => $r->etiqueta()])->all(),
        ]);
    }

    private function enviarAcceso(Empresa $empresa, Usuario $usuario, Rol $rol): void
    {
        $nueva = $usuario->email_verified_at === null;
        $enlace = $nueva ? URL::temporarySignedRoute('invitacion', now()->addHours(72), ['usuario' => $usuario->id]) : route('login');
        /** @var Usuario $yo */
        $yo = auth()->user();
        Mail::to($usuario->email)->queue(new AccesoEmpresa($empresa->nombre_comercial ?? $empresa->razon_social, $rol->etiqueta(), $yo->nombre, $enlace, $nueva));
    }

    /** El punto de venta debe ser de esta empresa (EmpresaScope + RLS); solo el cajero lo lleva. */
    private function puntoVenta(string $rol, int|string|null $id): ?int
    {
        return $rol === Rol::Cajero->value ? PuntoVenta::query()->findOrFail((int) $id)->id : null;
    }

    private function duenos(): int
    {
        return EmpresaUsuario::query()->where('rol', Rol::Dueno->value)->count();
    }
}
