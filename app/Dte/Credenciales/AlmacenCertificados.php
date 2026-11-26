<?php

namespace App\Dte\Credenciales;

use App\Models\Empresa;
use App\Models\EmpresaCredencial;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Guarda el certificado .crt de una empresa en el disco privado que monta el
 * firmador oficial (/uploads/{NIT}.crt). Permisos 600; nunca se sirve por HTTP.
 */
final class AlmacenCertificados
{
    public function guardar(Empresa $empresa, EmpresaCredencial $credencial, string $contenido): void
    {
        self::validar($contenido);
        if (! preg_match('/^\d{9}(\d{5})?$/', $empresa->nit)) {
            throw new InvalidArgumentException('El NIT de la empresa no es válido para nombrar el certificado.');
        }

        $nombre = $this->nombreArchivo($empresa);
        $this->disco()->put($nombre, $contenido, 'private'); // 0600 según config/filesystems.php
        @chmod($this->disco()->path($nombre), 0600);
        clearstatcache(true, $this->disco()->path($nombre));

        $credencial->certificado_sha256 = hash('sha256', $contenido);
        $credencial->certificado_cargado_at = now();
        $credencial->save();
    }

    /**
     * El certificado de Hacienda es un documento XML. Se exige XML bien formado y SIN DTD ni entidades
     * (evita XXE y «billion laughs»); el firmador valida después su contenido criptográfico.
     */
    public static function validar(string $contenido): void
    {
        if (trim($contenido) === '') {
            throw new InvalidArgumentException('El certificado está vacío.');
        }
        if (strlen($contenido) > 512 * 1024) {
            throw new InvalidArgumentException('El certificado es demasiado grande.');
        }
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $contenido)) {
            throw new InvalidArgumentException('El certificado no puede contener declaraciones DOCTYPE ni ENTITY.');
        }
        $anterior = libxml_use_internal_errors(true);
        $documento = new \DOMDocument;
        $ok = $documento->loadXML($contenido, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);
        if (! $ok || $documento->documentElement === null) {
            throw new InvalidArgumentException('El archivo no parece un certificado de Hacienda (.crt en formato XML).');
        }
    }

    public function existe(Empresa $empresa): bool
    {
        return $this->disco()->exists($this->nombreArchivo($empresa));
    }

    public function nombreArchivo(Empresa $empresa): string
    {
        return $empresa->nit.'.crt';
    }

    /** @return Filesystem&FilesystemAdapter */
    private function disco(): Filesystem
    {
        /** @var Filesystem&FilesystemAdapter */
        return Storage::disk((string) config('dte.firmador.disco_certificados'));
    }
}
