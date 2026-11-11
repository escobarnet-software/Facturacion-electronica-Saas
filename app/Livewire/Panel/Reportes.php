<?php

namespace App\Livewire\Panel;

use App\Dte\Reportes\ExportadorLibros;
use App\Dte\Reportes\LibrosIva;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Libros de IVA y resumen para la declaración mensual (F-07). */
#[Layout('layouts.panel')]
#[Title('Libros de IVA')]
class Reportes extends Component
{
    #[Url]
    public string $periodo = '';

    public function mount(): void
    {
        Gate::authorize('ver-libros');
        if (! preg_match('/^\d{4}-\d{2}$/', $this->periodo)) {
            $this->periodo = now('America/El_Salvador')->format('Y-m');
        }
    }

    public function render(LibrosIva $libros): View
    {
        $periodo = preg_match('/^\d{4}-\d{2}$/', $this->periodo) ? $this->periodo : now('America/El_Salvador')->format('Y-m');
        $desde = $periodo.'-01';
        $hasta = date('Y-m-t', (int) strtotime($desde));

        return view('livewire.panel.reportes', [
            'desde' => $desde,
            'hasta' => $hasta,
            'libros' => ExportadorLibros::LIBROS,
            'f07' => $libros->resumenF07($desde, $hasta),
            'retenciones' => $libros->retencionesPercepciones($desde, $hasta),
        ]);
    }
}
