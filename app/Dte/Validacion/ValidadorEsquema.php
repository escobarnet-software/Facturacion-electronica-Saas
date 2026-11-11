<?php

declare(strict_types=1);

namespace App\Dte\Validacion;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Revisa un DTE o evento contra el JSON Schema oficial de Hacienda ANTES de
 * firmarlo y enviarlo. Si algo falla aquí, se corrige sin gastar un envío.
 *
 * Los esquemas se toman de RegistroEsquemas (resources/schemas/), por tipo y
 * versión. Si no se indica la versión, se usa la de identificacion.version.
 *
 * Importante: cumplir el esquema NO garantiza que los cálculos fiscales estén
 * bien (MTIST v2, sección 6.1). Esos los aseguran los builders y sus pruebas.
 */
final class ValidadorEsquema
{
    private readonly RegistroEsquemas $registro;

    /** @var array<string, string> */
    private array $cache = [];

    public function __construct(string|RegistroEsquemas $esquemas)
    {
        $this->registro = $esquemas instanceof RegistroEsquemas ? $esquemas : new RegistroEsquemas($esquemas);
    }

    /**
     * @param  string  $clave  tipoDte ("01", "03"…) o evento ("invalidacion", "contingencia", "retorno", "operaciones_especiales")
     * @return array<string, string[]> errores por ruta del campo; vacío = válido
     */
    public function validar(string $json, string $clave, ?int $version = null): array
    {
        $documento = json_decode($json, flags: JSON_THROW_ON_ERROR);

        if ($version === null && is_object($documento)
            && isset($documento->identificacion) && is_object($documento->identificacion)
            && is_int($documento->identificacion->version ?? null)) {
            $version = $documento->identificacion->version;
        }

        $ruta = $this->registro->ruta($clave, $version);
        $this->cache[$ruta] ??= (string) file_get_contents($ruta);

        $validator = new Validator;
        $validator->setMaxErrors(50);
        $resultado = $validator->validate($documento, $this->cache[$ruta]);

        if ($resultado->isValid()) {
            return [];
        }

        $error = $resultado->error();

        return $error === null ? [] : (new ErrorFormatter)->format($error);
    }

    public function esValido(string $json, string $clave, ?int $version = null): bool
    {
        return $this->validar($json, $clave, $version) === [];
    }
}
