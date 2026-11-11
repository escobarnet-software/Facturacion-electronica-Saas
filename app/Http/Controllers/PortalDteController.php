<?php

namespace App\Http\Controllers;

use App\Dte\Representacion\ArchivoDte;
use App\Dte\Representacion\CodigoQr;
use App\Dte\Representacion\RepresentacionGrafica;
use App\Models\CatValor;
use App\Models\Dte;
use App\Models\Empresa;
use App\Tenancy\Tenancy;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * Portal público de descarga (Normativa regla 11): enlaces firmados, no adivinables y sin vencimiento.
 * Muestra lo mínimo exigido: emisor (nombre y NIT), modelo y tipo de transmisión, fecha y hora,
 * código de generación, sello, QR e instrucción breve.
 */
final class PortalDteController
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function mostrar(string $codigo): View
    {
        [$dte, $empresa] = $this->buscar($codigo);

        return view('dte.portal', [
            'dte' => $dte,
            'empresa' => $empresa,
            'modelo' => CatValor::query()->where(['catalogo' => 'CAT-003', 'codigo' => (string) $dte->modelo])->value('valor') ?? (string) $dte->modelo,
            'transmision' => CatValor::query()->where(['catalogo' => 'CAT-004', 'codigo' => (string) $dte->operacion])->value('valor') ?? (string) $dte->operacion,
            'qr' => CodigoQr::dataUri($dte),
            'urlJson' => URL::signedRoute('dte.portal.json', ['codigo' => $codigo]),
            'urlPdf' => URL::signedRoute('dte.portal.pdf', ['codigo' => $codigo]),
        ]);
    }

    public function json(string $codigo): Response
    {
        [$dte] = $this->buscar($codigo);

        return response(ArchivoDte::contenido($dte), 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.ArchivoDte::nombre($dte, 'json').'"',
        ]);
    }

    public function pdf(string $codigo, RepresentacionGrafica $representacion): Response
    {
        [$dte, $empresa] = $this->buscar($codigo);

        return response($representacion->pdf($dte, $empresa), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.ArchivoDte::nombre($dte, 'pdf').'"',
        ]);
    }

    /** @return array{0: Dte, 1: Empresa} */
    private function buscar(string $codigo): array
    {
        // Sin aislamiento: el enlace es público y firmado, y solo da acceso a ESTE documento.
        [$empresaId, $dteId] = $this->tenancy->sinAislamiento(function () use ($codigo) {
            $dte = Dte::query()->where('codigo_generacion', strtoupper($codigo))->whereNotNull('jws')->first();
            abort_if($dte === null, 404);

            return [$dte->empresa_id, $dte->id];
        });

        // Lo demás se lee con la empresa del documento fijada (EmpresaScope + RLS).
        $this->tenancy->establecer($empresaId);

        return [Dte::query()->findOrFail($dteId), Empresa::query()->findOrFail($empresaId)];
    }
}
