<?php

namespace App\Livewire\Panel;

use App\Models\Bitacora;
use App\Models\Cliente;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Catálogo de clientes (receptores). Los listados enmascaran el documento (datos personales). */
#[Layout('layouts.panel')]
#[Title('Clientes')]
class Clientes extends Component
{
    public ?int $editando = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        Gate::authorize('administrar-catalogos');
        $this->limpiar();
    }

    public function limpiar(): void
    {
        $this->editando = null;
        $this->form = ['tipo_documento' => '36', 'num_documento' => '', 'nrc' => null, 'nombre' => '', 'nombre_comercial' => null, 'cod_actividad' => null, 'desc_actividad' => null,
            'departamento' => null, 'municipio' => null, 'distrito' => null, 'complemento' => null, 'telefono' => null, 'correo' => null, 'gran_contribuyente' => false];
    }

    public function editar(int $id): void
    {
        $c = Cliente::query()->findOrFail($id);
        $this->editando = $c->id;
        $this->form = [...$c->only(['tipo_documento', 'num_documento', 'nrc', 'nombre', 'nombre_comercial', 'cod_actividad', 'desc_actividad', 'telefono', 'correo', 'gran_contribuyente']), ...((array) $c->direccion)];
    }

    public function guardar(): void
    {
        Gate::authorize('administrar-catalogos');
        $d = $this->validate([
            'form.tipo_documento' => 'required|in:36,13,37,03,02',
            'form.num_documento' => ['required', 'string', 'max:20', $this->form['tipo_documento'] === '36' ? 'regex:/^(\d{9}|\d{14})$/' : 'string'],
            'form.nrc' => 'nullable|regex:/^\d{2,8}$/',
            'form.nombre' => 'required|string|max:250',
            'form.nombre_comercial' => 'nullable|string|max:150',
            'form.cod_actividad' => 'nullable|string|min:5|max:6',
            'form.desc_actividad' => 'nullable|string|max:150',
            'form.departamento' => 'nullable|string|size:2',
            'form.municipio' => 'nullable|string|size:2',
            'form.distrito' => 'nullable|string|size:2',
            'form.complemento' => 'nullable|string|max:200',
            'form.telefono' => 'nullable|string|min:8|max:30',
            'form.correo' => 'nullable|email|max:100',
            'form.gran_contribuyente' => 'boolean',
        ], [], ['form.num_documento' => 'número de documento', 'form.nombre' => 'nombre'])['form'];

        $direccion = array_filter(['departamento' => $d['departamento'] ?? null, 'municipio' => $d['municipio'] ?? null, 'distrito' => $d['distrito'] ?? null, 'complemento' => $d['complemento'] ?? null]);
        $cliente = $this->editando ? Cliente::query()->findOrFail($this->editando) : new Cliente;
        $cliente->fill([...array_diff_key($d, array_flip(['departamento', 'municipio', 'distrito', 'complemento'])), 'direccion' => $direccion ?: null])->save();
        Bitacora::registrar($this->editando ? 'cliente.actualizado' : 'cliente.creado', $cliente);
        $this->limpiar();
    }

    public static function enmascarar(string $documento): string
    {
        return strlen($documento) <= 4 ? $documento : str_repeat('•', strlen($documento) - 4).substr($documento, -4);
    }

    public function render(): View
    {
        return view('livewire.panel.clientes', ['clientes' => Cliente::query()->orderBy('nombre')->paginate(50)]);
    }
}
