<?php

namespace App\Http\Controllers;

use App\Dte\Excepciones\DteException;
use App\Dte\Recibidos\ImportadorRecibidos;
use App\Models\Empresa;
use App\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Buzón de entrada de DTE recibidos: el servicio de correo entrante reenvía el mensaje completo
 * (MIME) a POST /buzon/{token}. Se importan los adjuntos .json.
 *
 * TODO: elegir el proveedor de correo entrante (Postmark, Mailgun, SES…) al definir el hosting (PLAN, decisión 2).
 */
final class BuzonEntradaController
{
    public function __invoke(Request $request, string $token, Tenancy $tenancy, ImportadorRecibidos $importador): JsonResponse
    {
        abort_if(strlen($token) < 32, 404);
        // Sin aislamiento SOLO para encontrar la empresa dueña del token secreto.
        $empresaId = $tenancy->sinAislamiento(fn () => Empresa::query()->where('buzon_token', $token)->value('id'));
        abort_if($empresaId === null, 404);

        $mime = $request->hasFile('email') ? (string) $request->file('email')?->get() : $request->getContent();
        $mensaje = (new MailMimeParser)->parse($mime, false);

        return $tenancy->ejecutarComo((int) $empresaId, function () use ($mensaje, $empresaId, $importador) {
            $empresa = Empresa::query()->findOrFail($empresaId);
            $resultado = ['importados' => [], 'rechazados' => []];

            foreach ($mensaje->getAllAttachmentParts() as $adjunto) {
                $nombre = (string) $adjunto->getFilename();
                if (! str_ends_with(strtolower($nombre), '.json')) {
                    continue;
                }
                try {
                    $resultado['importados'][] = $importador->importar($empresa, (string) $adjunto->getContent(), 'correo')->codigo_generacion;
                } catch (DteException $e) {
                    $resultado['rechazados'][] = ['archivo' => $nombre, 'motivo' => $e->getMessage()];
                }
            }

            return response()->json($resultado);
        });
    }
}
