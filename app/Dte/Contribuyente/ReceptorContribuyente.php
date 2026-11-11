<?php

declare(strict_types=1);

namespace App\Dte\Contribuyente;

/**
 * Receptor contribuyente (CCF, NC, ND, NR): va completo.
 * En CCF se identifica por NIT; en NC/ND/NR por tipo (CAT-022) y número de documento.
 */
final readonly class ReceptorContribuyente
{
    public function __construct(
        public string $nit,                 // número de documento (NIT 14/9 dígitos, sin guiones)
        public ?string $nrc,
        public string $nombre,
        public string $codActividad,        // CAT-019
        public string $descActividad,
        public string $departamento,        // CAT-012
        public string $municipio,           // CAT-013
        public string $distrito,            // CAT-008
        public string $complemento,
        public ?string $correo,
        public ?string $telefono = null,
        public ?string $nombreComercial = null,
        public string $tipoDocumento = '36', // CAT-022: 36 NIT
    ) {}

    /** @return array{departamento: string, municipio: string, distrito: string, complemento: string} */
    public function direccion(): array
    {
        return ['departamento' => $this->departamento, 'municipio' => $this->municipio, 'distrito' => $this->distrito, 'complemento' => $this->complemento];
    }
}
