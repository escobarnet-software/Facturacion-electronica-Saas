<?php

declare(strict_types=1);

namespace App\Dte\Validacion;

use RuntimeException;

/**
 * Registro de los JSON Schemas oficiales de Hacienda por tipo y versión.
 *
 * Fuente: resources/schemas/ (copia exacta de svfe-json-schemas.zip).
 * Cuando Hacienda publique una versión nueva, se agrega aquí sin borrar la
 * anterior: los documentos ya emitidos se siguen validando con su versión.
 */
final class RegistroEsquemas
{
    /** DTE: tipoDte (CAT-002) => [version => archivo]. */
    public const DTE = [
        '01' => [2 => 'fe-f-v2.json'],
        '03' => [4 => 'fe-ccf-v4.json'],
        '04' => [4 => 'fe-nr-v4.json'],
        '05' => [4 => 'fe-nc-v4.json'],
        '06' => [4 => 'fe-nd-v4.json'],
        '07' => [2 => 'fe-cr-v2.json'],
        '08' => [2 => 'fe-cl-v2.json'],
        '09' => [2 => 'fe-dcl-v2.json'],
        // La Normativa v2.0 dice versión 2 para FEXE, pero el esquema oficial vigente es v3: manda el esquema.
        '11' => [3 => 'fe-fex-v3.json'],
        '14' => [2 => 'fe-fse-v2.json'],
        '15' => [2 => 'fe-cd-v2.json'],
    ];

    /** Eventos: nombre => [version => archivo]. */
    public const EVENTOS = [
        'invalidacion' => [3 => 'invalidacion-schema-v3.json'],
        'contingencia' => [4 => 'contingencia-schema-v4.json'],
        'retorno' => [1 => 'fe-eret-v1.json'],
        'operaciones_especiales' => [1 => 'fe-eop-v1.json'],
    ];

    public function __construct(private readonly string $carpeta) {}

    /** Versión vigente (la más alta registrada) de un DTE o evento. */
    public function versionVigente(string $clave): int
    {
        return max(array_keys($this->versiones($clave)));
    }

    /** Ruta absoluta del esquema de un DTE o evento en una versión. */
    public function ruta(string $clave, ?int $version = null): string
    {
        $versiones = $this->versiones($clave);
        $version ??= max(array_keys($versiones));

        $archivo = $versiones[$version]
            ?? throw new RuntimeException("No hay esquema oficial para «{$clave}» en la versión {$version}.");

        $ruta = rtrim($this->carpeta, '/').'/'.$archivo;
        if (! is_file($ruta)) {
            throw new RuntimeException("No se encontró el esquema oficial: {$ruta}");
        }

        return $ruta;
    }

    /** @return array<int, string> */
    private function versiones(string $clave): array
    {
        return self::DTE[$clave] ?? self::EVENTOS[$clave]
            ?? throw new RuntimeException("Tipo de DTE o evento desconocido: «{$clave}».");
    }
}
