<?php

namespace App\Livewire\Panel;

use App\Dte\Validacion\ReglasHacienda as R;
use App\Models\Bitacora;
use App\Models\Cliente;
use App\Tenancy\Tenancy;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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

    /**
     * Reglas de Hacienda (esquemas y catálogos oficiales). Con NRC el cliente puede recibir Crédito
     * Fiscal, y el esquema del CCF exige además NIT, actividad económica y dirección completa.
     *
     * @return array<string, mixed>
     */
    protected function reglas(): array
    {
        $f = $this->form;
        $paraCcf = ($f['nrc'] ?? null) !== null && $f['nrc'] !== '';
        $dep = $f['departamento'] ?? null;
        $conDireccion = $paraCcf || ! empty($dep) || ! empty($f['complemento']);

        return [
            'form.tipo_documento' => [...R::tipoDocumento(), ...($paraCcf ? ['in:36'] : [])],
            'form.num_documento' => [...R::numeroDocumento($f['tipo_documento'] ?? null), Rule::unique('clientes', 'num_documento')
                ->where('empresa_id', app(Tenancy::class)->empresaId())->where('tipo_documento', $f['tipo_documento'] ?? '')->ignore($this->editando)],
            'form.nrc' => R::nrc(false),
            'form.nombre' => R::texto('nombre'),
            'form.nombre_comercial' => R::texto('nombre_comercial', false),
            'form.cod_actividad' => R::actividad($paraCcf),
            'form.desc_actividad' => R::texto('desc_actividad', $paraCcf),
            'form.departamento' => R::departamento($conDireccion),
            'form.municipio' => R::municipio($dep, $conDireccion),
            'form.distrito' => R::distrito($dep, $conDireccion),
            'form.complemento' => R::texto('complemento', $conDireccion),
            'form.telefono' => R::telefono(false),
            'form.correo' => R::correo(false),
            'form.gran_contribuyente' => 'boolean',
        ];
    }

    /** @return array<string, string> */
    protected function mensajes(): array
    {
        return [
            ...R::mensajes(['form.num_documento' => 'documento', 'form.nrc' => 'nrc', 'form.cod_actividad' => 'actividad', 'form.departamento' => 'departamento',
                'form.municipio' => 'municipio', 'form.distrito' => 'distrito', 'form.telefono' => 'telefono', 'form.correo' => 'correo', 'form.tipo_documento' => 'tipoDocumento']),
            'form.tipo_documento.in' => 'Para emitir Crédito Fiscal (cliente con NRC) el documento debe ser NIT.',
            'form.num_documento.unique' => 'Ya existe un cliente con ese documento.',
        ];
    }

    /** @return array<string, string> */
    protected function atributos(): array
    {
        return ['form.num_documento' => 'número de documento', 'form.nombre' => 'nombre o razón social', 'form.nombre_comercial' => 'nombre comercial',
            'form.cod_actividad' => 'actividad económica', 'form.desc_actividad' => 'descripción de la actividad', 'form.departamento' => 'departamento',
            'form.municipio' => 'municipio', 'form.distrito' => 'distrito', 'form.complemento' => 'dirección', 'form.telefono' => 'teléfono', 'form.correo' => 'correo'];
    }

    /** Validación en vivo: cada campo se revisa al salir de él. */
    public function updated(string $propiedad): void
    {
        $this->normalizar();
        if ($propiedad === 'form.departamento') {
            $this->form['municipio'] = $this->form['distrito'] = null;
        }
        if ($propiedad === 'form.cod_actividad') {
            $this->form['desc_actividad'] = R::nombreActividad($this->form['cod_actividad'] ?? null) ?? $this->form['desc_actividad'];
        }
        if (str_starts_with($propiedad, 'form.')) {
            $this->validateOnly($propiedad, $this->reglas(), $this->mensajes(), $this->atributos());
        }
    }

    public function guardar(): void
    {
        Gate::authorize('administrar-catalogos');
        $this->normalizar();
        $d = $this->validate($this->reglas(), $this->mensajes(), $this->atributos())['form'];

        $direccion = array_filter(['departamento' => $d['departamento'] ?? null, 'municipio' => $d['municipio'] ?? null, 'distrito' => $d['distrito'] ?? null, 'complemento' => $d['complemento'] ?? null]);
        $cliente = $this->editando ? Cliente::query()->findOrFail($this->editando) : new Cliente;
        $cliente->fill([...array_diff_key($d, array_flip(['departamento', 'municipio', 'distrito', 'complemento'])), 'direccion' => $direccion ?: null])->save();
        Bitacora::registrar($this->editando ? 'cliente.actualizado' : 'cliente.creado', $cliente);
        $this->limpiar();
        $this->resetValidation();
        session()->flash('cliente_guardado', 'Cliente guardado.');
    }

    private function normalizar(): void
    {
        $this->form['num_documento'] = R::documento($this->form['tipo_documento'] ?? null, $this->form['num_documento'] ?? null) ?? '';
        $this->form['nrc'] = R::soloDigitos($this->form['nrc'] ?? null);
        foreach (['nombre', 'nombre_comercial', 'complemento', 'correo', 'telefono', 'desc_actividad'] as $k) {
            if (is_string($this->form[$k] ?? null)) {
                $this->form[$k] = trim($this->form[$k]) === '' ? null : trim($this->form[$k]);
            }
        }
    }

    public static function enmascarar(string $documento): string
    {
        return strlen($documento) <= 4 ? $documento : str_repeat('•', strlen($documento) - 4).substr($documento, -4);
    }

    public function render(): View
    {
        $dep = $this->form['departamento'] ?? null;

        return view('livewire.panel.clientes', [
            'clientes' => Cliente::query()->orderBy('nombre')->paginate(50),
            'tiposDocumento' => R::opciones('CAT-022'),
            'departamentos' => R::opciones('CAT-012'),
            'municipios' => $dep ? R::opciones('CAT-013', $dep) : [],
            'distritos' => $dep ? R::opciones('CAT-008', $dep) : [],
            'actividades' => R::opciones('CAT-019'),
        ]);
    }
}
