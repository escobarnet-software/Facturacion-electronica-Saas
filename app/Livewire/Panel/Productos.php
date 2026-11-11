<?php

namespace App\Livewire\Panel;

use App\Models\Bitacora;
use App\Models\Producto;
use App\Tenancy\Tenancy;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Catálogo de productos y servicios (precio SIN IVA, a 8 decimales). */
#[Layout('layouts.panel')]
#[Title('Productos')]
class Productos extends Component
{
    public ?int $editando = null;

    /** @var array{codigo: string|null, codigo_barras: string|null, descripcion: string, precio: string, tipo_venta: string, tipo_item: int, uni_medida: int} */
    public array $form = ['codigo' => null, 'codigo_barras' => null, 'descripcion' => '', 'precio' => '0', 'tipo_venta' => 'gravada', 'tipo_item' => 1, 'uni_medida' => 59];

    public function mount(): void
    {
        Gate::authorize('administrar-catalogos');
    }

    public function editar(int $id): void
    {
        $p = Producto::query()->findOrFail($id);
        $this->editando = $p->id;
        $this->form = $p->only(['codigo', 'codigo_barras', 'descripcion', 'precio', 'tipo_venta', 'tipo_item', 'uni_medida']);
    }

    public function guardar(): void
    {
        Gate::authorize('administrar-catalogos');
        $datos = $this->validate([
            'form.codigo' => ['nullable', 'string', 'max:25', Rule::unique('productos', 'codigo')->where('empresa_id', app(Tenancy::class)->empresaId())->ignore($this->editando)],
            'form.codigo_barras' => 'nullable|string|max:50',
            'form.descripcion' => 'required|string|max:1000',
            'form.precio' => ['required', 'regex:/^\d+(\.\d{1,8})?$/'],
            'form.tipo_venta' => 'required|in:gravada,exenta,no_sujeta',
            'form.tipo_item' => 'required|integer|in:1,2,3,4',
            'form.uni_medida' => 'required|integer|min:1|max:99',
        ], [], ['form.precio' => 'precio', 'form.descripcion' => 'descripción', 'form.codigo' => 'código'])['form'];

        $producto = $this->editando ? Producto::query()->findOrFail($this->editando) : new Producto;
        $producto->fill($datos)->save();
        Bitacora::registrar($this->editando ? 'producto.actualizado' : 'producto.creado', $producto);
        $this->reset('editando', 'form');
    }

    public function render(): View
    {
        return view('livewire.panel.productos', ['productos' => Producto::query()->orderBy('descripcion')->paginate(50)]);
    }
}
