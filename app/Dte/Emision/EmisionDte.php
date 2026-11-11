<?php

declare(strict_types=1);

namespace App\Dte\Emision;

use App\Dte\Contribuyente\CreditoFiscalBuilder;
use App\Dte\Contribuyente\DocumentoContribuyente;
use App\Dte\Contribuyente\Linea;
use App\Dte\Contribuyente\ReceptorContribuyente;
use App\Dte\Contribuyente\TipoVenta;
use App\Dte\Correlativos\Correlativos;
use App\Dte\Excepciones\DteException;
use App\Dte\Factura\FacturaBuilder;
use App\Dte\Modelos\Emisor;
use App\Dte\Modelos\LineaVenta;
use App\Dte\Modelos\Pago;
use App\Dte\Modelos\Receptor;
use App\Dte\Modelos\Venta;
use App\Enums\EstadoDte;
use App\Jobs\TransmitirDte;
use App\Models\Bitacora;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Support\Decimal;
use Carbon\CarbonImmutable;

/**
 * Emite un DTE a partir de datos de negocio (panel, POS y API): la plataforma calcula los totales.
 *
 *  1. Toma el correlativo en su propia transacción (nunca se reutiliza).
 *  2. Arma el JSON con el builder del tipo (cálculos exactos) y lo guarda como «borrador».
 *  3. Encola la firma y transmisión (cola «transmision»). Con Hacienda caída, entra en contingencia
 *     y el documento se entrega igual (estado «transitorio»).
 *
 * Este paso cubre FE (01) y CCF (03); los demás tipos se emiten con sus builders vía API.
 */
final class EmisionDte
{
    public function __construct(private readonly Correlativos $correlativos) {}

    /**
     * @param  list<LineaSolicitud>  $lineas
     * @param  list<Pago>  $pagos
     */
    public function emitir(
        Empresa $empresa,
        Establecimiento $establecimiento,
        PuntoVenta $puntoVenta,
        string $tipo,
        array $lineas,
        ?Cliente $cliente = null,
        array $pagos = [],
        int $condicionOperacion = 1,
        ?string $observaciones = null,
        ?string $correoReceptor = null,
        bool $transmitir = true,
    ): Dte {
        $this->validarEmpresa($empresa);
        // Se arma una vista previa ANTES de tomar el correlativo: un error de datos no consume números.
        $this->previsualizar($empresa, $establecimiento, $puntoVenta, $tipo, $lineas, $cliente);

        $fecha = CarbonImmutable::now((string) config('dte.zona_horaria'));
        $correlativo = $this->correlativos->siguiente($empresa, $tipo, $establecimiento, $puntoVenta, $fecha);
        [$json, $codigo, $numero, $version] = $this->armar($empresa, $establecimiento, $puntoVenta, $tipo, $lineas, $cliente, $pagos, $condicionOperacion, $observaciones, $correoReceptor, $correlativo, $fecha);

        $documento = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $resumen = $documento['resumen'];
        $iva20 = array_values(array_filter((array) ($resumen['tributos'] ?? []), fn ($t) => ($t['codigo'] ?? null) === '20'))[0]['valor'] ?? 0;
        $iva = $tipo === '01' ? ($resumen['totalIva'] ?? 0) : $iva20;

        $dte = Dte::query()->create([
            'establecimiento_id' => $establecimiento->id,
            'punto_venta_id' => $puntoVenta->id,
            'tipo' => $tipo,
            'version' => $version,
            'ambiente' => $empresa->ambiente,
            'codigo_generacion' => $codigo,
            'numero_control' => $numero,
            'fecha_emision' => $fecha->format('Y-m-d'),
            'hora_emision' => $fecha->format('H:i:s'),
            'estado' => EstadoDte::Borrador,
            'receptor_nombre' => $documento['receptor']['nombre'] ?? null,
            'receptor_documento' => $documento['receptor']['numDocumento'] ?? $documento['receptor']['nit'] ?? null,
            'total_no_suj' => $this->texto($resumen['totalNoSuj'] ?? 0),
            'total_exenta' => $this->texto($resumen['totalExenta'] ?? 0),
            'total_gravada' => $this->texto($resumen['totalGravada'] ?? 0),
            'total_iva' => $this->texto($iva),
            'total_pagar' => $this->texto($resumen['totalPagar'] ?? 0),
            'json_texto' => $json,
            'json_original' => $documento,
        ]);
        Bitacora::registrar('dte.emitido', $dte, ['tipo' => $tipo, 'numeroControl' => $numero]);

        if ($transmitir) {
            TransmitirDte::dispatch($empresa->id, $dte->id);
        }

        return $dte->refresh();
    }

    /**
     * Resumen calculado SIN emitir (vista previa del panel y del POS): no toma correlativo ni guarda nada.
     *
     * @param  list<LineaSolicitud>  $lineas
     * @return array<string, mixed>
     */
    public function previsualizar(Empresa $empresa, Establecimiento $establecimiento, PuntoVenta $puntoVenta, string $tipo, array $lineas, ?Cliente $cliente = null): array
    {
        [$json] = $this->armar($empresa, $establecimiento, $puntoVenta, $tipo, $lineas, $cliente, [], 1, null, null, 1, CarbonImmutable::now((string) config('dte.zona_horaria')));

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR)['resumen'];
    }

    /**
     * @param  list<LineaSolicitud>  $lineas
     * @param  list<Pago>  $pagos
     * @return array{0: string, 1: string, 2: string, 3: int} [json, código, número de control, versión]
     */
    private function armar(Empresa $empresa, Establecimiento $establecimiento, PuntoVenta $puntoVenta, string $tipo, array $lineas, ?Cliente $cliente, array $pagos, int $condicionOperacion, ?string $observaciones, ?string $correoReceptor, int $correlativo, CarbonImmutable $fecha): array
    {
        if (! in_array($tipo, ['01', '03'], true)) {
            throw new DteException('Desde el panel se emiten Facturas (01) y Créditos Fiscales (03).');
        }
        if ($lineas === []) {
            throw new DteException('Agregue al menos un producto o servicio.');
        }
        if ($tipo === '03' && ($cliente === null || ! $cliente->puedeRecibirCcf())) {
            throw new DteException('Para un Crédito Fiscal el cliente debe estar inscrito en IVA (NRC), con actividad económica y dirección completa.');
        }
        $emisor = $this->emisor($empresa, $establecimiento, $puntoVenta);

        if ($tipo === '01') {
            $r = (new FacturaBuilder($emisor, $empresa->ambiente))->construir(new Venta(
                lineas: array_map(fn (LineaSolicitud $l) => $this->lineaFactura($l), $lineas),
                receptor: $this->receptorFactura($cliente, $correoReceptor),
                correlativo: $correlativo,
                fechaHora: $fecha->toDateTimeImmutable(),
                pagos: $pagos,
                condicionOperacion: $condicionOperacion,
                observaciones: $observaciones,
            ));

            return [$r->json, $r->codigoGeneracion, $r->numeroControl, FacturaBuilder::VERSION];
        }

        /** @var Cliente $cliente */
        $a = (new CreditoFiscalBuilder($emisor, $empresa->ambiente))->construir(new DocumentoContribuyente(
            lineas: array_map(fn (LineaSolicitud $l) => $this->lineaCcf($l), $lineas),
            receptor: $this->receptorCcf($cliente, $correoReceptor),
            correlativo: $correlativo,
            fechaHora: $fecha->toDateTimeImmutable(),
            pagos: $pagos,
            condicionOperacion: $condicionOperacion,
            observaciones: $observaciones,
        ));

        return [$a->json, $a->codigoGeneracion, $a->numeroControl, $a->version];
    }

    public function emisor(Empresa $empresa, Establecimiento $establecimiento, PuntoVenta $puntoVenta): Emisor
    {
        $direccion = $establecimiento->getAttribute('direccion') ?: $empresa->getAttribute('direccion') ?: [];

        return new Emisor(
            nit: $empresa->nit,
            nrc: (string) $empresa->nrc,
            nombre: $empresa->razon_social,
            codActividad: (string) $empresa->getAttribute('cod_actividad'),
            descActividad: (string) $empresa->getAttribute('desc_actividad'),
            nombreComercial: $empresa->nombre_comercial,
            departamento: (string) ($direccion['departamento'] ?? ''),
            municipio: (string) ($direccion['municipio'] ?? ''),
            distrito: (string) ($direccion['distrito'] ?? ''),
            complemento: (string) ($direccion['complemento'] ?? ''),
            telefono: (string) ($establecimiento->getAttribute('telefono') ?? $empresa->getAttribute('telefono')),
            correo: (string) ($establecimiento->getAttribute('correo') ?? $empresa->getAttribute('correo')),
            codEstable: $establecimiento->cod_estable_mh,
            codPuntoVenta: $puntoVenta->cod_punto_venta_mh,
        );
    }

    private function validarEmpresa(Empresa $empresa): void
    {
        $direccion = $empresa->getAttribute('direccion') ?? [];
        $faltan = array_filter([
            'NRC' => $empresa->nrc,
            'actividad económica' => $empresa->getAttribute('cod_actividad'),
            'dirección' => $direccion['complemento'] ?? null,
            'teléfono' => $empresa->getAttribute('telefono'),
            'correo' => $empresa->getAttribute('correo'),
        ], fn ($v) => $v === null || $v === '');
        if ($faltan !== []) {
            throw new DteException('Complete los datos de la empresa antes de emitir: '.implode(', ', array_keys($faltan)).'.');
        }
    }

    private function lineaFactura(LineaSolicitud $l): LineaVenta
    {
        if ($l->tipoVenta !== 'gravada' || ! Decimal::of($l->descuento)->isZero()) {
            throw new DteException('En la Factura, por ahora, solo se admiten ventas gravadas sin descuento por línea.');
        }
        // En la Factura el precio unitario INCLUYE el IVA: precio sin IVA × 1.13, a 8 decimales.
        $precio = Decimal::of($l->precioSinIva)->mul('1.13')->round(8);

        return new LineaVenta($l->descripcion, $l->cantidad, $precio, $l->uniMedida, $l->tipoItem, $l->codigo);
    }

    private function lineaCcf(LineaSolicitud $l): Linea
    {
        return new Linea($l->descripcion, $l->cantidad, $l->precioSinIva, TipoVenta::from($l->tipoVenta), $l->descuento, $l->tipoItem, $l->uniMedida, $l->codigo);
    }

    private function receptorFactura(?Cliente $c, ?string $correo): Receptor
    {
        if ($c === null) {
            return Receptor::consumidorFinal($correo);
        }
        $d = $c->direccion ?? [];

        return new Receptor(
            tipoDocumento: $c->tipo_documento, numDocumento: $c->num_documento, nrc: $c->nrc, nombre: $c->nombre,
            codActividad: $c->cod_actividad, descActividad: $c->desc_actividad,
            departamento: $d['departamento'] ?? null, municipio: $d['municipio'] ?? null, distrito: $d['distrito'] ?? null, complemento: $d['complemento'] ?? null,
            telefono: $c->telefono, correo: $correo ?? $c->correo,
        );
    }

    private function receptorCcf(Cliente $c, ?string $correo): ReceptorContribuyente
    {
        $d = (array) $c->direccion;

        return new ReceptorContribuyente(
            nit: $c->num_documento, nrc: $c->nrc, nombre: $c->nombre,
            codActividad: (string) $c->cod_actividad, descActividad: (string) $c->desc_actividad,
            departamento: (string) $d['departamento'], municipio: (string) $d['municipio'], distrito: (string) $d['distrito'], complemento: (string) $d['complemento'],
            correo: $correo ?? $c->correo, telefono: $c->telefono, nombreComercial: $c->nombre_comercial, tipoDocumento: $c->tipo_documento,
        );
    }

    private function texto(int|float|string $numero): string
    {
        return is_float($numero) ? (string) json_encode($numero) : (string) $numero;
    }
}
