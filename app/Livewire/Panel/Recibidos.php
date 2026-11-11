<?php

namespace App\Livewire\Panel;

use App\Dte\Excepciones\DteException;
use App\Dte\Recibidos\ImportadorRecibidos;
use App\Models\DteRecibido;
use App\Models\Empresa;
use App\Tenancy\Tenancy;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/** DTE recibidos de proveedores: subir el JSON o recibirlo en el buzón de correo de la empresa. */
#[Layout('layouts.panel')]
#[Title('Documentos recibidos')]
class Recibidos extends Component
{
    use WithFileUploads;
    use WithPagination;

    /** @var list<TemporaryUploadedFile> */
    #[Validate(['archivos' => 'required|array|max:50', 'archivos.*' => 'file|max:512'])]
    public array $archivos = [];

    /** @var list<string> */
    public array $mensajes = [];

    public function mount(): void
    {
        Gate::authorize('ver-libros');
    }

    public function importar(ImportadorRecibidos $importador, Tenancy $tenancy): void
    {
        $this->validate();
        $empresa = Empresa::query()->findOrFail($tenancy->empresaId());
        $this->mensajes = [];
        foreach ($this->archivos as $archivo) {
            try {
                $r = $importador->importar($empresa, (string) $archivo->get());
                $this->mensajes[] = "{$archivo->getClientOriginalName()}: registrado ({$r->numero_control}).";
            } catch (DteException $e) {
                $this->mensajes[] = "{$archivo->getClientOriginalName()}: {$e->getMessage()}";
            }
        }
        $this->archivos = [];
    }

    /** Genera (o cambia) la dirección secreta del buzón de entrada. */
    public function generarBuzon(Tenancy $tenancy): void
    {
        Gate::authorize('administrar-empresa');
        Empresa::query()->whereKey($tenancy->empresaId())->update(['buzon_token' => Str::random(48)]);
    }

    public function render(Tenancy $tenancy): View
    {
        $token = Empresa::query()->whereKey($tenancy->empresaId())->value('buzon_token');

        return view('livewire.panel.recibidos', [
            'recibidos' => DteRecibido::query()->orderByDesc('fecha_emision')->orderByDesc('id')->paginate(25),
            'buzon' => $token ? route('buzon', $token) : null,
        ]);
    }
}
