<?php

namespace App\Http\Controllers;

use App\Dte\Reportes\ExportadorLibros;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class LibroDescargaController
{
    public function __invoke(Request $request, string $libro, string $formato, ExportadorLibros $exportador): Response
    {
        Gate::authorize('ver-libros');
        $datos = $request->validate([
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ]);
        $nombre = "libro-{$libro}-{$datos['desde']}-{$datos['hasta']}";

        if ($formato === 'csv') {
            return response($exportador->csv($libro, $datos['desde'], $datos['hasta']), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$nombre}.csv\"",
            ]);
        }

        return response()->download($exportador->xlsx($libro, $datos['desde'], $datos['hasta']), "{$nombre}.xlsx")->deleteFileAfterSend();
    }
}
