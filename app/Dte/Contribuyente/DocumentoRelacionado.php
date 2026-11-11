<?php

declare(strict_types=1);

namespace App\Dte\Contribuyente;

use DateTimeInterface;

/** Documento relacionado (Anexo II): tipo (CAT-002), generación (CAT-007: 1 físico, 2 electrónico), número y fecha. */
final readonly class DocumentoRelacionado
{
    public function __construct(
        public string $tipoDocumento,
        public string $numeroDocumento,     // electrónico: código de generación; físico: número del documento
        public DateTimeInterface $fechaEmision,
        public int $tipoGeneracion = 2,
    ) {}

    /** @return array{tipoDocumento: string, tipoGeneracion: int, numeroDocumento: string, fechaEmision: string} */
    public function comoArreglo(): array
    {
        return [
            'tipoDocumento' => $this->tipoDocumento,
            'tipoGeneracion' => $this->tipoGeneracion,
            'numeroDocumento' => $this->numeroDocumento,
            'fechaEmision' => $this->fechaEmision->format('Y-m-d'),
        ];
    }
}
