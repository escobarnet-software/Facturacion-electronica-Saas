<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Dte\Excepciones\DteException;
use App\Dte\Factura\FacturaBuilder;
use App\Dte\Modelos\Emisor;
use App\Dte\Modelos\LineaVenta;
use App\Dte\Modelos\Receptor;
use App\Dte\Modelos\Venta;
use App\Dte\Validacion\ValidadorEsquema;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;

/**
 * Demostración: arma la factura de la pupusería del curso (módulo 7),
 * la muestra y la valida contra el esquema oficial de Hacienda.
 *
 *   php artisan dte:demo-factura
 */
final class DteDemoFactura extends Command
{
    protected $signature = 'dte:demo-factura {--compacto : Muestra el JSON en una sola línea}';

    protected $description = 'Arma y valida la Factura (01) de ejemplo de la pupusería';

    public function handle(): int
    {
        // Datos de DEMOSTRACIÓN. Los códigos de municipio y distrito deben
        // tomarse del catálogo oficial (CAT-013 y CAT-008).
        $emisor = new Emisor(
            nit: '06140000000000',
            nrc: '1234567',
            nombre: 'PUPUSERIA LA ESQUINA S.A. DE C.V.',
            codActividad: '56101',
            descActividad: 'Restaurantes',
            nombreComercial: 'Pupusería La Esquina',
            departamento: '06',
            municipio: '23',
            distrito: '14',
            complemento: 'Calle Principal 123, San Salvador',
            telefono: '22220000',
            correo: 'ventas@laesquina.com',
            codEstable: 'M001',
            codPuntoVenta: 'P001',
        );

        $venta = new Venta(
            lineas: [
                new LineaVenta('Pupusa revuelta', '6', '0.75'),
                new LineaVenta('Horchata', '2', '1.00'),
            ],
            receptor: Receptor::consumidorFinal('cliente@correo.com'),
            correlativo: 1,
            fechaHora: new DateTimeImmutable('now', new DateTimeZone(config('dte.zona_horaria'))),
        );

        try {
            $factura = (new FacturaBuilder($emisor, config('dte.ambiente')))->construir($venta);
        } catch (DteException $e) {
            $this->error('No se pudo armar la factura: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->line($this->option('compacto') ? $factura->json : $factura->jsonLegible());
        $this->newLine();
        $this->info("Número de control:  {$factura->numeroControl}");
        $this->info("Código generación:  {$factura->codigoGeneracion}");
        $this->info("Total a pagar:      \${$factura->totalPagar->toFixed(2)}");
        $this->info("IVA incluido:       \${$factura->totalIva->toFixed(2)}");

        if (!class_exists(\Opis\JsonSchema\Validator::class)) {
            $this->warn('Para validar contra el esquema oficial instale: composer require opis/json-schema');

            return self::SUCCESS;
        }

        $errores = (new ValidadorEsquema(config('dte.esquemas')))->validar($factura->json, '01');
        if ($errores === []) {
            $this->info('✔ La factura cumple el esquema oficial fe-f-v2.json de Hacienda.');

            return self::SUCCESS;
        }

        $this->error('✘ La factura NO cumple el esquema oficial:');
        foreach ($errores as $campo => $mensajes) {
            $this->line("  {$campo}: " . implode('; ', $mensajes));
        }

        return self::FAILURE;
    }
}
