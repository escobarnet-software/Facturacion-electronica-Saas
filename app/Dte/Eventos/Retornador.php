<?php

declare(strict_types=1);

namespace App\Dte\Eventos;

use App\Dte\Excepciones\DteException;
use App\Dte\Hacienda\HaciendaClient;
use App\Dte\Hacienda\MensajesHacienda;
use App\Dte\Support\CodigoGeneracion;
use App\Dte\Support\NumeroALetras;
use App\Enums\EstadoDte;
use App\Models\Bitacora;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\EmpresaCredencial;
use App\Models\Establecimiento;
use App\Models\Evento;
use App\Models\PuntoVenta;
use App\Support\Decimal;
use App\Support\DteJson;
use Carbon\CarbonImmutable;

/**
 * Evento de Retorno (Normativa 13.3; esquema fe-eret v1): devoluciones y reembolsos en FE, FEX y FSE.
 *
 *  - Solo sobre documentos con sello, hasta 3 meses desde el sello.
 *  - No es descuento, no puede superar lo vendido (sumando retornos anteriores) y no genera crédito fiscal.
 *  - NO invalida el original: lo deja «ajustado».
 *  - Cada evento se relaciona con un solo documento.
 *
 * TODO(normativa): el MTIST no detalla el servicio para eventos de retorno; se transmite por el servicio
 * de recepción con tipoDte 18 (CAT-002 «Evento de Retorno») y versión 1.
 */
final class Retornador
{
    public const VERSION = 1;

    public const TIPO_EVENTO = '18';

    public function __construct(
        private readonly Plazos $plazos = new Plazos,
        private readonly FirmaEventos $firma = new FirmaEventos,
    ) {}

    /**
     * @param  list<array{descripcion: string, cantidad: string, precio: string, descuento?: string, tipoItem?: int, uniMedida?: int, codigo?: string|null}>  $lineas
     */
    public function armar(Dte $dte, Empresa $empresa, array $lineas, CarbonImmutable $momento, ?string $codigoGeneracion = null): string
    {
        $this->validarOriginal($dte, $momento);
        if ($lineas === [] || count($lineas) > 2000) {
            throw new DteException('El retorno debe tener entre 1 y 2,000 líneas.');
        }

        $esFe = $dte->tipo === '01';
        $esFex = $dte->tipo === '11';
        $esFse = $dte->tipo === '14';

        $cuerpo = [];
        $suma = Decimal::zero();
        $sumaIva = Decimal::zero();
        foreach (array_values($lineas) as $i => $l) {
            $n = $i + 1;
            $cantidad = Decimal::of($l['cantidad'])->round(8);
            $precio = Decimal::of($l['precio'])->round(8);
            $descuento = Decimal::of($l['descuento'] ?? '0')->round(8);
            if (! $cantidad->isPositive() || $precio->isNegative() || $descuento->isNegative()) {
                throw new DteException("Línea {$n}: cantidad, precio o descuento inválidos.");
            }
            $valor = $cantidad->mul($precio)->sub($descuento)->round(8);
            if ($valor->isNegative()) {
                throw new DteException("Línea {$n}: el descuento es mayor que el valor de la línea.");
            }
            $iva = $esFe ? $valor->mul(13)->div(113, 8) : Decimal::zero();
            $suma = $suma->add($valor);
            $sumaIva = $sumaIva->add($iva);
            $cero = Decimal::zero();

            $cuerpo[] = [
                'numItem' => $n, 'tipoItem' => $l['tipoItem'] ?? 1, 'codigoGeneracion' => $dte->codigo_generacion,
                'cantidad' => $cantidad, 'precioUni' => $precio, 'descripcion' => $l['descripcion'],
                'codigo' => $l['codigo'] ?? null, 'uniMedida' => $l['uniMedida'] ?? 59, 'montoDescu' => $descuento,
                'codTributo' => null, 'ventaNoSuj' => $cero, 'ventaExenta' => $cero,
                'ventaGravada' => $esFse ? $cero : $valor, 'compra' => $esFse ? $valor : $cero,
                'tributos' => $esFex && $valor->isPositive() ? ['C3'] : null,
                'psv' => $cero, 'ivaItem' => $iva, 'noGravado' => $cero, 'seguro' => $cero, 'flete' => $cero,
                'ivaRete' => $cero, 'reteRenta' => $cero,
            ];
        }

        $total = $suma->round(2);
        $this->validarNoSuperaLoVendido($dte, $total);

        $original = $dte->json_original;
        $emisorOriginal = $original['emisor'] ?? [];
        $receptor = $original['receptor'] ?? [];
        $establecimiento = Establecimiento::query()->findOrFail($dte->establecimiento_id);
        $puntoVenta = PuntoVenta::query()->findOrFail($dte->punto_venta_id);
        $ahora = $momento->setTimezone(Plazos::zona());
        $cero = Decimal::zero();

        return DteJson::encode([
            'identificacion' => [
                'version' => self::VERSION, 'ambiente' => $dte->ambiente, 'tipoModelo' => 1, 'tipoOperacion' => 1,
                'tipoEvento' => self::TIPO_EVENTO, 'tipoContingencia' => null, 'motivoContin' => null,
                'codigoGeneracion' => $codigoGeneracion ?? CodigoGeneracion::nuevo(),
                'fecEmi' => $ahora->format('Y-m-d'), 'horEmi' => $ahora->format('H:i:s'), 'fusion' => null, 'tipoMoneda' => 'USD',
            ],
            // Cada evento se relaciona con un solo documento.
            'documentoRelacionado' => [[
                'tipoDocumento' => $dte->tipo, 'codigoGeneracion' => $dte->codigo_generacion, 'fechaEmision' => $dte->fecha_emision->format('Y-m-d'),
            ]],
            'emisor' => [
                'nit' => $empresa->nit, 'nombre' => $empresa->razon_social,
                'codEstableMH' => $establecimiento->cod_estable_mh, 'codEstable' => $establecimiento->cod_estable,
                'codPuntoVentaMH' => $puntoVenta->cod_punto_venta_mh, 'codPuntoVenta' => $puntoVenta->cod_punto_venta,
                'recintoFiscal' => $esFex ? ($emisorOriginal['recintoFiscal'] ?? null) : null,
                'tipoRegimen' => $esFex ? ($emisorOriginal['tipoRegimen'] ?? null) : null,
                'regimen' => $esFex ? ($emisorOriginal['regimen'] ?? null) : null,
                'tipoItemExpor' => $esFex ? ($emisorOriginal['tipoItemExpor'] ?? null) : null,
            ],
            'documento' => [
                'tipoDocumento' => $receptor['tipoDocumento'] ?? null,
                'numDocumento' => $receptor['numDocumento'] ?? null,
                'nombre' => $receptor['nombre'] ?? null,
                'codPais' => $receptor['codPais'] ?? null,
                'nombrePais' => $receptor['nombrePais'] ?? null,
                'telefono' => $receptor['telefono'] ?? null,
                'correo' => $receptor['correo'] ?? null,
            ],
            'ventaTercero' => null,
            'compraTercero' => null,
            'cuerpoDocumento' => $cuerpo,
            'resumen' => [
                'totalNoSuj' => $cero, 'totalExenta' => $cero,
                'totalGravada' => $esFse ? $cero : $total,
                'totalCompraExcluidos' => $esFse ? $total : $cero,
                'subTotalVentas' => $total,
                'tributos' => $esFex ? [['codigo' => 'C3', 'descripcion' => 'Impuesto al Valor Agregado (exportaciones) 0%', 'valor' => $cero]] : null,
                'totalSeguro' => $esFex ? $cero : null,
                'totalFlete' => $esFex ? $cero : null,
                'montoTotalOperacion' => $total,
                'ivaRete' => $cero,
                'reteRenta' => $esFse ? $cero : null,
                'totalNoGravado' => $cero,
                'totalPagar' => $total,
                'totalLetras' => NumeroALetras::convertir($total),
                'totalNoOnerosas' => $cero,
                'totalIva' => $sumaIva->round(2),
                'saldoFavor' => $cero,
            ],
            'apendice' => null,
        ]);
    }

    /**
     * @param  list<array{descripcion: string, cantidad: string, precio: string, descuento?: string, tipoItem?: int, uniMedida?: int, codigo?: string|null}>  $lineas
     */
    public function registrar(Dte $dte, Empresa $empresa, EmpresaCredencial $credencial, array $lineas, ?CarbonImmutable $momento = null): Evento
    {
        $momento ??= CarbonImmutable::now(Plazos::zona());
        $evento = $this->firma->firmarYGuardar($empresa, $credencial, Evento::RETORNO, self::VERSION, $this->armar($dte, $empresa, $lineas, $momento), $dte->id);

        $respuesta = (new HaciendaClient($empresa, $credencial))->recibirDte((string) $evento->jws, self::TIPO_EVENTO, self::VERSION, $evento->codigo_generacion, $evento->id);
        $sello = $respuesta['selloRecibido'] ?? null;

        if (($respuesta['estado'] ?? null) === 'PROCESADO' && is_string($sello) && $sello !== '') {
            $evento->forceFill(['estado' => 'procesado', 'sello_recibido' => $sello, 'respuesta_mh' => $respuesta, 'fh_procesamiento' => now()])->save();
            // El retorno NO invalida el documento: lo deja ajustado.
            $dte->transicionar(EstadoDte::Ajustado, 'Ajustado por el Evento de Retorno '.$evento->codigo_generacion, respuesta: $respuesta);
            Bitacora::registrar('dte.retorno', $dte, ['evento' => $evento->codigo_generacion]);

            return $evento;
        }

        $evento->forceFill(['estado' => 'rechazado', 'respuesta_mh' => $respuesta])->save();
        throw new DteException('Hacienda rechazó el retorno: '.MensajesHacienda::traducir(
            isset($respuesta['codigoMsg']) ? (string) $respuesta['codigoMsg'] : null,
            isset($respuesta['descripcionMsg']) ? (string) $respuesta['descripcionMsg'] : null,
        ));
    }

    private function validarOriginal(Dte $dte, CarbonImmutable $momento): void
    {
        if (! in_array($dte->tipo, ['01', '11', '14'], true)) {
            throw new DteException('El Evento de Retorno solo aplica a Facturas, Facturas de Exportación y de Sujeto Excluido.');
        }
        if ($dte->sello_recibido === null || ! in_array($dte->estado, [EstadoDte::Transmitido, EstadoDte::Observado, EstadoDte::Ajustado], true)) {
            throw new DteException('Solo se registran retornos sobre documentos con sello de recepción y vigentes.');
        }
        if (! $this->plazos->dentroDelPlazo($momento, $this->plazos->limiteRetorno($dte))) {
            throw new DteException('FECHA FUERA DE PLAZO PERMITIDO: el retorno se registra dentro de los 3 meses desde el sello.');
        }
    }

    private function validarNoSuperaLoVendido(Dte $dte, Decimal $total): void
    {
        $vendido = Decimal::of((string) ($dte->json_original['resumen']['totalPagar'] ?? $dte->json_original['resumen']['totalCompra'] ?? '0'));
        $anteriores = Evento::query()->where('tipo', Evento::RETORNO)->where('dte_id', $dte->id)->where('estado', 'procesado')->get()
            ->reduce(fn (Decimal $s, Evento $e) => $s->add((string) ($e->json_original['resumen']['totalPagar'] ?? '0')), Decimal::zero());

        if ($anteriores->add($total)->compareTo($vendido) > 0) {
            throw new DteException('El retorno no puede superar lo vendido (incluyendo los retornos anteriores).');
        }
    }
}
