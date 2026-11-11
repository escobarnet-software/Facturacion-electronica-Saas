<?php

namespace App\Livewire\Panel;

use App\Models\Bitacora;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
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
        $d = $this->validate([
            'establecimiento.tipo' => 'required|in:01,02,04,07,20',
            'establecimiento.nombre' => 'required|string|max:150',
            'establecimiento.cod_estable_mh' => ['required', 'regex:/^[MBSP]\d{3}$/'],
        ], ['establecimiento.cod_estable_mh.regex' => 'El código de Hacienda tiene la forma M001, S001, B001 o P001.'])['establecimiento'];
        $e = Establecimiento::query()->create($d);
        Bitacora::registrar('establecimiento.creado', $e);
        $this->reset('establecimiento');
    }

    public function crearPuntoVenta(): void
    {
        Gate::authorize('administrar-catalogos');
        $d = $this->validate([
            'puntoVenta.establecimiento_id' => 'required|integer',
            'puntoVenta.nombre' => 'required|string|max:150',
            'puntoVenta.cod_punto_venta_mh' => ['required', 'regex:/^P\d{3}$/'],
        ], ['puntoVenta.cod_punto_venta_mh.regex' => 'El código de Hacienda del punto de venta tiene la forma P001.'])['puntoVenta'];
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
