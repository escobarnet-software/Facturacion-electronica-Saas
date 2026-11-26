<?php

namespace App\Livewire\Panel;

use App\Dte\Eventos\DatosInvalidacion;
use App\Dte\Eventos\Invalidador;
use App\Dte\Eventos\Plazos;
use App\Dte\Excepciones\DteException;
use App\Dte\Hacienda\HaciendaException;
use App\Dte\Validacion\ReglasHacienda as R;
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
        // Esquema oficial de invalidación v3: nombre de 1 a 100, documento de 1 a 20, motivo de 1 a 200;
        // el tipo 1 y el 3 exigen el código de generación del documento que reemplaza.
        $this->numDocSolicita = (string) R::documento($this->tipDocSolicita, $this->numDocSolicita);
        $this->codigoReemplazo = strtoupper(trim((string) $this->codigoReemplazo)) ?: null;
        $this->validate([
            'dteId' => 'required|integer',
            'tipoAnulacion' => 'required|in:1,2,3',
            'nombreSolicita' => 'required|string|min:3|max:100',
            'tipDocSolicita' => R::tipoDocumento(),
            'numDocSolicita' => R::numeroDocumento($this->tipDocSolicita),
            'motivo' => [$this->tipoAnulacion === 3 ? 'required' : 'nullable', 'string', 'min:1', 'max:200'],
            'codigoReemplazo' => [in_array($this->tipoAnulacion, [1, 3], true) ? 'required' : 'nullable', 'regex:/^[A-F0-9]{8}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{12}$/'],
        ], [...R::mensajes(['numDocSolicita' => 'documento', 'tipDocSolicita' => 'tipoDocumento']),
            'codigoReemplazo.required' => 'Para este tipo de invalidación indique el código de generación del documento que lo reemplaza.',
            'codigoReemplazo.regex' => 'El código de generación tiene 36 caracteres, por ejemplo 9C3C7A2E-1B4F-4D2A-9E61-0A1B2C3D4E5F.',
            'motivo.required' => 'Escriba el motivo de la invalidación.'],
            ['nombreSolicita' => 'nombre de quien solicita', 'numDocSolicita' => 'número de documento', 'motivo' => 'motivo', 'codigoReemplazo' => 'código del documento que reemplaza']);
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
