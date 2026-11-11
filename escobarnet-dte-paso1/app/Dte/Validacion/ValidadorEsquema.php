<?php

declare(strict_types=1);

namespace App\Dte\Validacion;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use RuntimeException;

/**
 * Revisa un DTE contra el JSON Schema oficial de Hacienda ANTES de firmarlo
 * y enviarlo. Si algo falla aquí, se corrige sin gastar un envío a Hacienda.
 *
 * Requiere: composer require opis/json-schema
 * Los esquemas oficiales se descargan de factura.gob.sv → Documentos Técnicos
 * → "Json Schemas" y se guardan en storage/app/dte/schemas/.
 *
 * Importante: cumplir el esquema NO garantiza que los cálculos fiscales estén
 * bien (Manual Tecnológico v2.0, sección 6.1). Esos los asegura el builder.
 */
final class ValidadorEsquema
{
    private const ESQUEMAS = [
        '01' => 'fe-f-v2.json',
        // Se agregan al implementar cada tipo:
        // '03' => 'fe-ccf-v4.json', '05' => 'fe-nc-v4.json', '06' => 'fe-nd-v4.json',
        // '11' => 'fe-fex-v3.json', '14' => 'fe-fse-v2.json',
    ];

    public function __construct(private readonly string $carpetaEsquemas)
    {
    }

    /**
     * @return array<string, string[]> errores por ruta del campo; vacío = válido
     */
    public function validar(string $json, string $tipoDte): array
    {
        $archivo = self::ESQUEMAS[$tipoDte] ?? null;
        if ($archivo === null) {
            throw new RuntimeException("No hay esquema configurado para el tipo de DTE {$tipoDte}.");
        }

        $ruta = rtrim($this->carpetaEsquemas, '/') . '/' . $archivo;
        if (!is_file($ruta)) {
            throw new RuntimeException("No se encontró el esquema oficial: {$ruta}");
        }

        $validator = new Validator();
        $validator->setMaxErrors(50);
        $resultado = $validator->validate(
            json_decode($json, flags: JSON_THROW_ON_ERROR),
            file_get_contents($ruta),
        );

        if ($resultado->isValid()) {
            return [];
        }

        return (new ErrorFormatter())->format($resultado->error());
    }
}
