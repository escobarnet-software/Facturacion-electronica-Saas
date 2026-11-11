<?php

declare(strict_types=1);

namespace App\Dte\Pruebas;

use App\Dte\Contribuyente\CreditoFiscalBuilder;
use App\Dte\Contribuyente\DocumentoContribuyente;
use App\Dte\Contribuyente\DocumentoRelacionado;
use App\Dte\Contribuyente\Linea;
use App\Dte\Contribuyente\NotaCreditoBuilder;
use App\Dte\Contribuyente\NotaDebitoBuilder;
use App\Dte\Contribuyente\NotaRemisionBuilder;
use App\Dte\Contribuyente\ReceptorContribuyente;
use App\Dte\Emision\EmisionDte;
use App\Dte\Especiales\ContableLiquidacionBuilder;
use App\Dte\Especiales\DonacionBuilder;
use App\Dte\Especiales\ExportacionBuilder;
use App\Dte\Especiales\LiquidacionBuilder;
use App\Dte\Especiales\RetencionBuilder;
use App\Dte\Especiales\SujetoExcluidoBuilder;
use App\Dte\Factura\FacturaBuilder;
use App\Dte\Factura\FacturaResultado;
use App\Dte\Modelos\LineaVenta;
use App\Dte\Modelos\Receptor;
use App\Dte\Modelos\Venta;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use DateTimeImmutable;
use LogicException;

/**
 * Arma documentos de prueba válidos de cada tipo, con datos variados (cantidades y precios
 * distintos en cada número de prueba), para las pruebas mínimas del ambiente 00.
 *
 * El receptor contribuyente es la propia empresa: su NIT y NRC existen en Hacienda, lo que evita
 * rechazos por «NIT no existe» al usar datos inventados.
 */
final class GeneradorDocumentosPrueba
{
    public function __construct(private readonly EmisionDte $emision) {}

    /**
     * @return array{json: string, codigo: string, numero: string, version: int}
     */
    public function armar(Empresa $empresa, Establecimiento $establecimiento, PuntoVenta $puntoVenta, string $tipo, int $n, int $correlativo, DateTimeImmutable $fecha, ?Dte $ccfRelacionado = null): array
    {
        $emisor = $this->emision->emisor($empresa, $establecimiento, $puntoVenta);
        $ambiente = $empresa->ambiente === '01' ? throw new LogicException('Las pruebas mínimas se ejecutan en el ambiente de pruebas (00).') : '00';
        $cantidad = (string) (($n % 5) + 1);
        $precio = sprintf('%d.%02d', 5 + ($n % 17), ($n * 7) % 100);
        $hoy = $fecha->format('Y-m-d');
        $d = (array) $empresa->getAttribute('direccion');
        $propia = new ReceptorContribuyente(
            $empresa->nit, (string) $empresa->nrc, $empresa->razon_social, (string) $empresa->getAttribute('cod_actividad'), (string) $empresa->getAttribute('desc_actividad'),
            (string) ($d['departamento'] ?? ''), (string) ($d['municipio'] ?? ''), (string) ($d['distrito'] ?? ''), (string) ($d['complemento'] ?? ''),
            (string) $empresa->getAttribute('correo'), (string) $empresa->getAttribute('telefono'),
        );
        $linea = fn (?string $doc = null) => Linea::gravada("Producto de prueba {$n}", $cantidad, $precio, '0', 'PRU-'.($n % 10), $doc);
        $relacion = $ccfRelacionado
            ? [$ccfRelacionado->codigo_generacion, new DocumentoRelacionado('03', $ccfRelacionado->codigo_generacion, $ccfRelacionado->fecha_emision->toDateTimeImmutable())]
            : ['AC54E9DB-79C5-42CE-B430-EC552C97EFBA', new DocumentoRelacionado('03', 'AC54E9DB-79C5-42CE-B430-EC552C97EFBA', $fecha)];

        $armado = match ($tipo) {
            '01' => (new FacturaBuilder($emisor, $ambiente))->construir(new Venta(
                [new LineaVenta("Producto de prueba {$n}", $cantidad, $precio, 59, 1, 'PRU-'.($n % 10))],
                Receptor::consumidorFinal(), $correlativo, $fecha,
            )),
            '03' => (new CreditoFiscalBuilder($emisor, $ambiente))->construir(new DocumentoContribuyente([$linea()], $propia, $correlativo, $fecha)),
            '04' => (new NotaRemisionBuilder($emisor, $ambiente))->construir(new DocumentoContribuyente([$linea()], $propia, $correlativo, $fecha, bienTitulo: '04')),
            '05' => (new NotaCreditoBuilder($emisor, $ambiente))->construir(new DocumentoContribuyente(
                [Linea::gravada("Devolución de prueba {$n}", '1', '1.00', '0', null, $relacion[0])], $propia, $correlativo, $fecha, relacionados: [$relacion[1]],
            )),
            '06' => (new NotaDebitoBuilder($emisor, $ambiente))->construir(new DocumentoContribuyente(
                [Linea::gravada("Ajuste de prueba {$n}", '1', '1.00', '0', null, $relacion[0])], $propia, $correlativo, $fecha, relacionados: [$relacion[1]],
            )),
            '07' => (new RetencionBuilder)->construir($emisor, $propia, [[
                'tipoDte' => '03', 'tipoGeneracion' => 1, 'numeroDocumento' => (string) (1000 + $n), 'fechaEmision' => $hoy,
                'montoSujetoGrav' => sprintf('%d.00', 100 + $n), 'codigoRetencionMH' => '22', 'descripcion' => "Compra de prueba {$n}",
            ]], $correlativo, $fecha, $ambiente),
            '08' => (new LiquidacionBuilder)->construir($emisor, $propia, 1, [[
                'tipoDte' => '03', 'tipoGeneracion' => 1, 'numeroDocumento' => (string) (2000 + $n), 'fechaEmision' => $hoy, 'ventaGravada' => sprintf('%d.00', 50 + $n),
            ]], $correlativo, $fecha, $ambiente),
            '09' => (new ContableLiquidacionBuilder)->construir($emisor,
                ['nit' => $empresa->nit, 'nrc' => $empresa->nrc, 'nombre' => $empresa->razon_social, 'codActividad' => (string) $empresa->getAttribute('cod_actividad'),
                    'descActividad' => (string) $empresa->getAttribute('desc_actividad'), 'tipoEstablecimiento' => $establecimiento->tipo, 'correo' => (string) $empresa->getAttribute('correo')],
                ['inicio' => $fecha->modify('first day of this month')->format('Y-m-d'), 'fin' => $hoy, 'valorOperaciones' => sprintf('%d.00', 113 * (($n % 5) + 1)),
                    'montoSujetoPercepcion' => sprintf('%d.00', 100 * (($n % 5) + 1)), 'iva' => sprintf('%d.00', 13 * (($n % 5) + 1))],
                ['nombEntrega' => (string) ($empresa->getAttribute('responsable_nombre') ?: 'Responsable'), 'docuEntrega' => (string) ($empresa->getAttribute('responsable_num_doc') ?: '000000000')],
                $correlativo, $fecha, $ambiente),
            '11' => (new ExportacionBuilder)->construir($emisor, ['tipoItemExpor' => 2],
                ['nombre' => 'CLIENTE DEL EXTERIOR DE PRUEBA', 'tipoDocumento' => '37', 'numDocumento' => 'EXT-'.$n, 'descActividad' => 'Servicios de prueba',
                    'codPais' => 'US', 'nombrePais' => 'Estados Unidos', 'complemento' => 'Miami, Florida', 'tipoPersona' => 2],
                [['descripcion' => "Servicio de prueba {$n}", 'cantidad' => $cantidad, 'precio' => $precio, 'tipoItem' => 2]], $correlativo, $fecha, $ambiente),
            '14' => (new SujetoExcluidoBuilder)->construir($emisor,
                ['tipoDocumento' => '13', 'numDocumento' => (string) ($empresa->getAttribute('responsable_num_doc') ?: '000000000'), 'nombre' => 'Sujeto excluido de prueba',
                    'departamento' => (string) ($d['departamento'] ?? ''), 'municipio' => (string) ($d['municipio'] ?? ''), 'distrito' => (string) ($d['distrito'] ?? ''), 'complemento' => (string) ($d['complemento'] ?? '')],
                [['descripcion' => "Compra de prueba {$n}", 'cantidad' => $cantidad, 'precio' => $precio]], $correlativo, $fecha, $ambiente),
            '15' => (new DonacionBuilder)->construir($emisor, '36',
                ['tipoDocumento' => '36', 'numDocumento' => $empresa->nit, 'nombre' => $empresa->razon_social, 'codDomiciliado' => 1, 'codPais' => '9300'],
                [['tipoDonacion' => 1, 'descripcion' => "Donación de prueba {$n}", 'cantidad' => '1', 'valorUni' => $precio]],
                [['codDocAsociado' => 1, 'descDocumento' => 'Resolución de prueba', 'detalleDocumento' => 'Resolución de donatario de prueba']],
                $correlativo, $fecha, $ambiente),
            default => throw new LogicException("Tipo sin generador de pruebas: {$tipo}"),
        };

        return match (true) {
            $armado instanceof FacturaResultado => ['json' => $armado->json, 'codigo' => $armado->codigoGeneracion, 'numero' => $armado->numeroControl, 'version' => FacturaBuilder::VERSION],
            default => ['json' => $armado->json, 'codigo' => $armado->codigoGeneracion, 'numero' => $armado->numeroControl, 'version' => $armado->version],
        };
    }
}
