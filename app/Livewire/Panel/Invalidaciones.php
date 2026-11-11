<?php

namespace App\Livewire\Panel;

use App\Dte\Eventos\DatosInvalidacion;
use App\Dte\Eventos\Invalidador;
use App\Dte\Eventos\Plazos;
use App\Dte\Excepciones\DteException;
use App\Dte\Hacienda\HaciendaException;
use App\Enums\EstadoDte;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\EmpresaCredencial;
use App\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Invalidaciones: documentos con sello y el plazo que les queda, y asistente del evento (Cuadro 6). */
#[Layout('layouts.panel')]
#[Title('Invalidaciones')]
class Invalidaciones extends Component
{
    #[Url(as: 'dte')]
    public ?int $dteId = null;

    public int $tipoAnulacion = 2;

    public ?string $motivo = null;

    public ?string $codigoReemplazo = null;

    public string $nombreSolicita = '';

    public string $tipDocSolicita = '13';

    public string $numDocSolicita = '';

    public ?string $error = null;

    public ?string $mensaje = null;

    public function mount(): void
    {
        Gate::authorize('invalidar');
    }

    public function invalidar(Invalidador $invalidador): void
    {
        Gate::authorize('invalidar');
        $this->error = $this->mensaje = null;
        $this->validate([
            'dteId' => 'required|integer',
            'tipoAnulacion' => 'required|in:1,2,3',
            'nombreSolicita' => 'required|string|max:100',
            'numDocSolicita' => 'required|string|max:20',
            'motivo' => 'nullable|string|max:200',
        ]);
        $empresa = Empresa::query()->findOrFail(app(Tenancy::class)->empresaId());
        $dte = Dte::query()->findOrFail($this->dteId);

        try {
            $evento = $invalidador->invalidar($dte, $empresa, EmpresaCredencial::query()->where('ambiente', $dte->ambiente)->firstOrFail(), new DatosInvalidacion(
                $this->tipoAnulacion,
                (string) $empresa->getAttribute('responsable_nombre'), (string) ($empresa->getAttribute('responsable_tipo_doc') ?? '13'), (string) $empresa->getAttribute('responsable_num_doc'),
                $this->nombreSolicita, $this->tipDocSolicita, $this->numDocSolicita,
                $this->motivo ?: null, $this->codigoReemplazo ?: null,
            ));
        } catch (DteException|HaciendaException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->mensaje = "Documento {$dte->numero_control} invalidado. Sello del evento: {$evento->sello_recibido}.";
        $this->dteId = null;
    }

    public function render(): View
    {
        $plazos = new Plazos;
        $ahora = CarbonImmutable::now(Plazos::zona());
        $documentos = Dte::query()->whereNotNull('sello_recibido')
            ->whereIn('estado', [EstadoDte::Transmitido->value, EstadoDte::Observado->value, EstadoDte::Ajustado->value])
            ->orderByDesc('fecha_emision')->limit(100)->get()
            ->map(function (Dte $d) use ($plazos, $ahora) {
                $limite = $plazos->limiteInvalidacion($d);

                return ['dte' => $d, 'limite' => $limite, 'vencido' => $ahora->greaterThan($limite), 'dias' => (int) floor($ahora->diffInDays($limite, false))];
            });

        return view('livewire.panel.invalidaciones', [
            'documentos' => $documentos,
            'seleccionado' => $this->dteId ? Dte::query()->find($this->dteId) : null,
        ]);
    }
}
