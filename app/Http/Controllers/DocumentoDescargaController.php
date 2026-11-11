<?php

namespace App\Http\Controllers;

use App\Dte\Representacion\ArchivoDte;
use App\Dte\Representacion\RepresentacionGrafica;
use App\Enums\Rol;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Usuario;
use App\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** Descarga del PDF (carta o ticket) y del Archivo DTE desde el panel. Dte usa EmpresaScope: otra empresa → 404. */
final class DocumentoDescargaController
{
    public function pdf(Request $request, Dte $dte, RepresentacionGrafica $representacion, Tenancy $tenancy): Response
    {
        $this->autorizar($dte);
        $formato = $request->query('formato') === 'ticket' ? 'ticket' : 'carta';

        return response($representacion->pdf($dte, Empresa::query()->findOrFail($tenancy->empresaId()), $formato), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.ArchivoDte::nombre($dte, 'pdf').'"',
        ]);
    }

    public function json(Dte $dte): Response
    {
        $this->autorizar($dte);

        return response(ArchivoDte::contenido($dte), 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.ArchivoDte::nombre($dte, 'json').'"',
        ]);
    }

    private function autorizar(Dte $dte): void
    {
        Gate::authorize('ver-documentos');
        /** @var Usuario $usuario */
        $usuario = auth()->user();
        if ($usuario->rolActual() === Rol::Cajero && $dte->punto_venta_id !== $usuario->puntoVentaActual()) {
            abort(403);
        }
        abort_if($dte->jws === null, 404);
    }
}
