<?php

declare(strict_types=1);

namespace App\Dte\Representacion;

use App\Models\Dte;
use App\Models\Empresa;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;

/**
 * Archivo DTE que se entrega al receptor (Normativa regla 11; Anexo I, Cuadro 11; MTIST 6.5):
 * la estructura de datos tal como se firmó + "firmaElectronica" + "selloRecibido".
 *
 * Se arma sobre el TEXTO exacto firmado (sin decodificar ni volver a codificar números), y se
 * conserva inalterado en el disco del archivo (S3 con versionado y object lock) con su SHA-256.
 * Los documentos en contingencia se entregan sin sello (aún no lo tienen).
 */
final class ArchivoDte
{
    public static function nombre(Dte $dte, string $extension): string
    {
        return "{$dte->codigo_generacion}.{$extension}";
    }

    public static function contenido(Dte $dte, bool $conSello = true): string
    {
        if ($dte->jws === null) {
            throw new LogicException('El documento aún no está firmado.');
        }
        $texto = rtrim($dte->json_texto);
        if (! str_ends_with($texto, '}')) {
            throw new RuntimeException('El JSON del documento no es un objeto válido.');
        }

        $extra = ',"firmaElectronica":'.json_encode($dte->jws, JSON_UNESCAPED_SLASHES);
        if ($conSello && $dte->sello_recibido !== null) {
            $extra .= ',"selloRecibido":'.json_encode($dte->sello_recibido);
        }

        return substr($texto, 0, -1).$extra.'}';
    }

    /** Ruta en el archivo: {NIT}/{AAAA}/{MM}/{código}.{ext} (orden cronológico por período). */
    public static function ruta(Dte $dte, Empresa $empresa, string $extension): string
    {
        return $empresa->nit.'/'.$dte->fecha_emision->format('Y/m').'/'.self::nombre($dte, $extension);
    }

    /**
     * Guarda el JSON y el PDF. Lo ya archivado no se reemplaza: si el contenido difiere, falla.
     *
     * @return array{json: string, pdf: string, sha256: string}
     */
    public function guardar(Dte $dte, Empresa $empresa, string $pdf): array
    {
        $json = self::contenido($dte);
        $hash = hash('sha256', $json);
        $rutaJson = self::ruta($dte, $empresa, 'json');
        $rutaPdf = self::ruta($dte, $empresa, 'pdf');

        $disco = self::disco();
        if ($disco->exists($rutaJson)) {
            $guardado = (string) $disco->get($rutaJson);
            // Única excepción: la versión entregada en contingencia (sin sello) se completa con el sello.
            $eraContingencia = $dte->sello_recibido !== null && $guardado === self::contenido($dte, conSello: false);
            if ($eraContingencia) {
                $disco->put($rutaJson, $json);
            } elseif (hash('sha256', $guardado) !== $hash) {
                throw new RuntimeException("El archivo {$rutaJson} ya existe con otro contenido: el archivo es inmutable.");
            }
        } else {
            $disco->put($rutaJson, $json);
        }
        // El PDF se puede regenerar si el error está solo en la representación (Normativa 11.3).
        $disco->put($rutaPdf, $pdf);

        $dte->forceFill(['archivo_json_sha256' => $hash, 'pdf_path' => $rutaPdf])->save();

        return ['json' => $rutaJson, 'pdf' => $rutaPdf, 'sha256' => $hash];
    }

    public static function disco(): Filesystem
    {
        return Storage::disk((string) config('dte.archivo.disco'));
    }
}
