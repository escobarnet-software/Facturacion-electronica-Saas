<?php

declare(strict_types=1);

namespace App\Dte\Modelos;

/**
 * Cliente que recibe el DTE. Para un consumidor final en ventas menores a
 * $25,000 NO se le puede exigir identificación (aviso de la DGII): en ese caso
 * se usa Receptor::consumidorFinal(), opcionalmente solo con el correo.
 */
final readonly class Receptor
{
    public function __construct(
        public ?string $tipoDocumento = null,   // catálogo CAT-022 (13 DUI, 36 NIT, 03 pasaporte...)
        public ?string $numDocumento = null,
        public ?string $nrc = null,
        public ?string $nombre = null,
        public ?string $codActividad = null,
        public ?string $descActividad = null,
        public ?string $departamento = null,
        public ?string $municipio = null,
        public ?string $distrito = null,
        public ?string $complemento = null,
        public ?string $telefono = null,
        public ?string $correo = null,
    ) {}

    public static function consumidorFinal(?string $correo = null): self
    {
        return new self(correo: $correo);
    }

    public function estaIdentificado(): bool
    {
        return $this->tipoDocumento !== null && $this->numDocumento !== null && $this->nombre !== null;
    }

    public function tieneDireccion(): bool
    {
        return $this->departamento !== null && $this->municipio !== null
            && $this->distrito !== null && $this->complemento !== null;
    }
}
