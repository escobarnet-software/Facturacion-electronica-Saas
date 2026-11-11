<?php

namespace App\Livewire\Panel;

use App\Enums\EstadoDte;
use App\Enums\Rol;
use App\Jobs\TransmitirDte;
use App\Models\Dte;
use App\Models\Usuario;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Documentos emitidos: filtros, columnas como las del portal de Hacienda, acciones y exportación a Excel. */
#[Layout('layouts.panel')]
#[Title('Documentos emitidos')]
class Documentos extends Component
{
    use WithPagination;

    #[Url]
    public ?string $desde = null;

    #[Url]
    public ?string $hasta = null;

    #[Url]
    public ?string $tipo = null;

    #[Url]
    public ?string $estado = null;

    #[Url]
    public ?string $receptor = null;

    #[Url]
    public ?string $codigo = null;

    public ?string $mensaje = null;

    public function mount(): void
    {
        Gate::authorize('ver-documentos');
    }

    public function updating(string $propiedad): void
    {
        if (in_array($propiedad, ['desde', 'hasta', 'tipo', 'estado', 'receptor', 'codigo'], true)) {
            $this->resetPage();
        }
    }

    /** @return Builder<Dte> */
    public function consulta(): Builder
    {
        /** @var Usuario $usuario */
        $usuario = auth()->user();
        $q = Dte::query()->orderByDesc('fecha_emision')->orderByDesc('id');

        // El cajero solo consulta lo emitido desde su punto de venta.
        if ($usuario->rolActual() === Rol::Cajero) {
            $q->where('punto_venta_id', $usuario->puntoVentaActual() ?? 0);
        }
        if ($this->desde) {
            $q->where('fecha_emision', '>=', $this->desde);
        }
        if ($this->hasta) {
            $q->where('fecha_emision', '<=', $this->hasta);
        }
        if ($this->tipo) {
            $q->where('tipo', $this->tipo);
        }
        if ($this->estado) {
            $q->where('estado', $this->estado);
        }
        if ($this->receptor) {
            $texto = '%'.mb_strtolower(trim($this->receptor)).'%';
            $q->where(fn (Builder $w) => $w->whereRaw('lower(receptor_nombre) like ?', [$texto])->orWhere('receptor_documento', 'like', $texto));
        }
        if ($this->codigo) {
            $c = strtoupper(trim($this->codigo));
            $q->where(fn (Builder $w) => $w->where('codigo_generacion', $c)->orWhere('numero_control', $c));
        }

        return $q;
    }

    public function reenviar(int $id): void
    {
        Gate::authorize('emitir');
        $dte = $this->consulta()->findOrFail($id);
        if (! in_array($dte->estado, [EstadoDte::Rechazado, EstadoDte::Firmado, EstadoDte::Validado, EstadoDte::Borrador], true)) {
            $this->mensaje = 'Este documento no necesita reenviarse.';

            return;
        }
        // Rechazado y corregido: se reenvía con el MISMO código de generación.
        TransmitirDte::dispatch($dte->empresa_id, $dte->id);
        $this->mensaje = "Reenvío de {$dte->numero_control} programado.";
    }

    public function exportar(): StreamedResponse
    {
        Gate::authorize('ver-documentos');
        $documentos = $this->consulta()->get();

        return response()->streamDownload(function () use ($documentos) {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(['Tipo', 'Número de control', 'Código de generación', 'Tipo doc. receptor', 'N° documento receptor', 'Receptor', 'Fecha de generación', 'Fecha de recepción', 'Monto', 'IVA', 'Estado', 'Sello']));
            foreach ($documentos as $d) {
                $writer->addRow(Row::fromValues([
                    $d->tipo, $d->numero_control, $d->codigo_generacion,
                    $d->json_original['receptor']['tipoDocumento'] ?? (isset($d->json_original['receptor']['nit']) ? '36' : ''),
                    (string) $d->receptor_documento, (string) $d->receptor_nombre,
                    $d->fecha_emision->format('d/m/Y'), $d->fh_procesamiento?->setTimezone('America/El_Salvador')->format('d/m/Y H:i:s') ?? '',
                    (float) $d->getRawOriginal('total_pagar'), (float) $d->getRawOriginal('total_iva'), $d->estado->etiqueta(), (string) $d->sello_recibido,
                ]));
            }
            $writer->close();
        }, 'documentos-'.now('America/El_Salvador')->format('Ymd-His').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function render(): View
    {
        return view('livewire.panel.documentos', ['documentos' => $this->consulta()->paginate(25)]);
    }
}
