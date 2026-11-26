<?php

namespace App\Livewire\Panel;

use App\Dte\Validacion\ReglasHacienda as R;
use App\Models\Bitacora;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Plataforma\Limites;
use App\Tenancy\Tenancy;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Establecimientos (con el código que asigna Hacienda) y sus puntos de venta. */
#[Layout('layouts.panel')]
#[Title('Establecimientos')]
class Establecimientos extends Component
{
    /** @var array{tipo: string, nombre: string, cod_estable_mh: string} */
    public array $establecimiento = ['tipo' => '01', 'nombre' => '', 'cod_estable_mh' => ''];

    /** @var array{establecimiento_id: int|null, nombre: string, cod_punto_venta_mh: string} */
    public array $puntoVenta = ['establecimiento_id' => null, 'nombre' => '', 'cod_punto_venta_mh' => ''];

    public function mount(): void
    {
        Gate::authorize('administrar-catalogos');
    }

    public function crearEstablecimiento(): void
    {
        Gate::authorize('administrar-catalogos');
        $this->establecimiento['cod_estable_mh'] = strtoupper(trim((string) ($this->establecimiento['cod_estable_mh'] ?? '')));
        $d = $this->validate([
            'establecimiento.tipo' => 'required|in:01,02,04,07,20',
            'establecimiento.nombre' => 'required|string|max:150',
            'establecimiento.cod_estable_mh' => [...R::codigoEstablecimiento(), Rule::unique('establecimientos', 'cod_estable_mh')->where('empresa_id', app(Tenancy::class)->empresaId())],
        ], [...R::mensajes(['establecimiento.cod_estable_mh' => 'establecimiento']), 'establecimiento.cod_estable_mh.unique' => 'Ya existe un establecimiento con ese código.'],
            ['establecimiento.nombre' => 'nombre', 'establecimiento.cod_estable_mh' => 'código de Hacienda'])['establecimiento'];
        if (($limite = Limites::establecimiento()) !== null) {
            $this->addError('establecimiento.nombre', $limite);

            return;
        }
        $e = Establecimiento::query()->create($d);
        Bitacora::registrar('establecimiento.creado', $e);
        $this->reset('establecimiento');
    }

    public function crearPuntoVenta(): void
    {
        Gate::authorize('administrar-catalogos');
        $this->puntoVenta['cod_punto_venta_mh'] = strtoupper(trim((string) ($this->puntoVenta['cod_punto_venta_mh'] ?? '')));
        $d = $this->validate([
            'puntoVenta.establecimiento_id' => 'required|integer',
            'puntoVenta.nombre' => 'required|string|max:150',
            'puntoVenta.cod_punto_venta_mh' => [...R::codigoPuntoVenta(), Rule::unique('puntos_venta', 'cod_punto_venta_mh')->where('empresa_id', app(Tenancy::class)->empresaId())->where('establecimiento_id', (int) ($this->puntoVenta['establecimiento_id'] ?? 0))],
        ], [...R::mensajes(['puntoVenta.cod_punto_venta_mh' => 'puntoVenta']), 'puntoVenta.cod_punto_venta_mh.unique' => 'Ese establecimiento ya tiene un punto de venta con ese código.', 'puntoVenta.establecimiento_id.required' => 'Elija el establecimiento.'],
            ['puntoVenta.nombre' => 'nombre', 'puntoVenta.cod_punto_venta_mh' => 'código de Hacienda'])['puntoVenta'];
        Establecimiento::query()->findOrFail($d['establecimiento_id']); // debe ser de la empresa activa
        $pv = PuntoVenta::query()->create($d);
        Bitacora::registrar('punto_venta.creado', $pv);
        $this->reset('puntoVenta');
    }

    public function render(): View
    {
        return view('livewire.panel.establecimientos', ['establecimientos' => Establecimiento::query()->with('puntosVenta')->orderBy('cod_estable_mh')->get()]);
    }
}
