<?php

declare(strict_types=1);

namespace App\Dte\Modelos;

/**
 * Datos del negocio que emite el DTE. En el sistema real se guardan en la
 * base de datos (pantalla "Configuración del emisor") y se cargan por cliente.
 */
final readonly class Emisor
{
    public function __construct(
        public string $nit,             // 14 dígitos, sin guiones
        public string $nrc,             // sin guiones
        public string $nombre,          // razón social
        public string $codActividad,    // catálogo CAT-019
        public string $descActividad,
        public ?string $nombreComercial,
        public string $departamento,    // catálogo CAT-012
        public string $municipio,       // catálogo CAT-013
        public string $distrito,        // catálogo CAT-008
        public string $complemento,     // calle, número, colonia
        public string $telefono,
        public string $correo,
        public string $codEstable,      // ejemplo: M001
        public string $codPuntoVenta,   // ejemplo: P001
    ) {}
}
