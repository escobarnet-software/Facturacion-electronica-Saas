<?php

namespace App\Livewire\Panel;

use App\Dte\Validacion\ReglasHacienda as R;
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
        $this->form['codigo'] = trim((string) ($this->form['codigo'] ?? '')) ?: null;
        $this->form['codigo_barras'] = trim((string) ($this->form['codigo_barras'] ?? '')) ?: null;
        $datos = $this->validate($this->reglas(), $this->mensajes(), $this->atributos())['form'];

        $producto = $this->editando ? Producto::query()->findOrFail($this->editando) : new Producto;
        $producto->fill($datos)->save();
        Bitacora::registrar($this->editando ? 'producto.actualizado' : 'producto.creado', $producto);
        $this->reset('editando', 'form');
    }

    /**
     * Esquemas oficiales (cuerpoDocumento): código de 1 a 25, descripción de 1 a 1500, precio unitario
     * de 0 a menos de 100 000 000 000 con 8 decimales, unidad de medida del CAT-014 y tipo de ítem del CAT-011.
     *
     * @return array<string, mixed>
     */
    protected function reglas(): array
    {
        return [
            'form.codigo' => [...R::texto('codigo', false), 'regex:/^[A-Za-z0-9._\/-]+$/', Rule::unique('productos', 'codigo')->where('empresa_id', app(Tenancy::class)->empresaId())->ignore($this->editando)],
            'form.codigo_barras' => ['nullable', 'string', 'max:50', 'regex:/^[0-9A-Za-z-]+$/'],
            'form.descripcion' => R::texto('descripcion'),
            'form.precio' => R::monto(),
            'form.tipo_venta' => 'required|in:gravada,exenta,no_sujeta',
            'form.tipo_item' => R::tipoItem(),
            'form.uni_medida' => R::unidadMedida(),
        ];
    }

    /** @return array<string, string> */
    protected function mensajes(): array
    {
        return [...R::mensajes(['form.precio' => 'monto', 'form.uni_medida' => 'unidad']),
            'form.codigo.regex' => 'El código solo puede tener letras, números, punto, guion o barra (sin espacios).',
            'form.codigo.unique' => 'Ya existe un producto con ese código.',
            'form.codigo_barras.regex' => 'El código de barras solo puede tener números, letras y guiones.'];
    }

    /** @return array<string, string> */
    protected function atributos(): array
    {
        return ['form.precio' => 'precio', 'form.descripcion' => 'descripción', 'form.codigo' => 'código', 'form.codigo_barras' => 'código de barras', 'form.uni_medida' => 'unidad de medida', 'form.tipo_item' => 'tipo de ítem'];
    }

    public function updated(string $propiedad): void
    {
        if (str_starts_with($propiedad, 'form.')) {
            $this->validateOnly($propiedad, $this->reglas(), $this->mensajes(), $this->atributos());
        }
    }

    public function render(): View
    {
        return view('livewire.panel.productos', ['unidades' => R::opciones('CAT-014'), 'tiposItem' => R::opciones('CAT-011'), 'productos' => Producto::query()->orderBy('descripcion')->paginate(50)]);
    }
}
