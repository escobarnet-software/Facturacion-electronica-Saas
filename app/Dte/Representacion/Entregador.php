<?php

declare(strict_types=1);

namespace App\Dte\Representacion;

use App\Mail\EntregaDte;
use App\Models\Bitacora;
use App\Models\Dte;
use App\Models\Empresa;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use LogicException;

/**
 * Entrega al receptor (Normativa regla 11): archiva el JSON y el PDF (inmutables, con SHA-256),
 * y envía el correo con ambos adjuntos y el enlace firmado al portal de descarga.
 * La entrega no se retiene por falta de pago (no consulta la suscripción).
 */
final class Entregador
{
    public function __construct(
        private readonly RepresentacionGrafica $representacion = new RepresentacionGrafica,
        private readonly ArchivoDte $archivo = new ArchivoDte,
    ) {}

    public static function enlacePortal(Dte $dte): string
    {
        // Firmado y sin vencimiento: el portal debe estar disponible siempre.
        return URL::signedRoute('dte.portal', ['codigo' => $dte->codigo_generacion]);
    }

    public function entregar(Dte $dte, Empresa $empresa, ?string $correo = null): void
    {
        if ($dte->jws === null) {
            throw new LogicException('Solo se entregan documentos firmados.');
        }

        $pdf = $this->representacion->pdf($dte, $empresa);
        $this->archivo->guardar($dte, $empresa, $pdf);

        $correo ??= $dte->json_original['receptor']['correo'] ?? null;
        if (! is_string($correo) || ! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            Bitacora::registrar('dte.entrega_sin_correo', $dte);

            return; // queda disponible en el portal; se puede entregar por otro medio
        }

        $total = $dte->json_original['resumen']['totalPagar'] ?? $dte->json_original['resumen']['total'] ?? $dte->json_original['resumen']['valorTotal'] ?? null;

        Mail::to($correo)->send(new EntregaDte([
            'emisor' => $empresa->razon_social,
            'nitEmisor' => $empresa->nit,
            'tipo' => (string) $this->representacion->datos($dte, $empresa)['titulo'],
            'numeroControl' => $dte->numero_control,
            'codigoGeneracion' => $dte->codigo_generacion,
            'sello' => $dte->sello_recibido,
            'fecha' => $dte->fecha_emision->format('d/m/Y').' '.substr((string) $dte->hora_emision, 0, 8),
            'total' => $total === null ? null : '$'.number_format((float) $total, 2),
            'enlace' => self::enlacePortal($dte),
            'contingencia' => $dte->operacion === 2 && $dte->sello_recibido === null,
        ], ArchivoDte::contenido($dte), $pdf));

        $dte->forceFill(['entregado_at' => now(), 'entregado_a' => $correo])->save();
        Bitacora::registrar('dte.entregado', $dte, ['medio' => 'correo']);
    }
}
