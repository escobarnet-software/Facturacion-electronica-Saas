<?php

declare(strict_types=1);

namespace App\Dte\Eventos;

use App\Dte\Excepciones\DteException;
use App\Dte\Hacienda\HaciendaClient;
use App\Dte\Hacienda\MensajesHacienda;
use App\Dte\Support\CodigoGeneracion;
use App\Dte\Support\NumeroALetras;
use App\Models\Empresa;
use App\Models\EmpresaCredencial;
use App\Models\Evento;
use App\Support\Decimal;
use App\Support\DteJson;
use Carbon\CarbonImmutable;

/**
 * Evento de Operaciones Especiales (Normativa 13.4; esquema fe-eop v1): empresas autorizadas por
 * resolución a usar Facturas de Venta Simplificada (02) o comprobantes de control interno (97), CAT-023.
 * Detalle por rangos y plazo hasta el 10.º día hábil del mes siguiente.
 *
 * TODO(normativa): en la Venta Simplificada el precio incluye IVA; el tributo 20 se informa como IVA
 * contenido (gravado × 13/113) y el total es la suma de las operaciones. Se transmite por el servicio
 * de recepción con tipoDte 17 (CAT-002), versión 1: el MTIST no detalla un servicio propio.
 */
final class OperacionesEspeciales
{
    public const VERSION = 1;

    public const TIPO_EVENTO = '17';

    public function __construct(
        private readonly Plazos $plazos = new Plazos,
        private readonly FirmaEventos $firma = new FirmaEventos,
    ) {}

    /**
     * @param  list<array{tipoDocumento: string, fechaEmision: string, cantidad: int, descripcion: string, docDel?: string|null, docAl?: string|null, precioUni?: string, ventaNoSuj?: string, ventaExenta?: string, ventaGravada?: string, numDocumento?: string|null}>  $rangos
     */
    public function armar(Empresa $empresa, string $ambiente, array $rangos, CarbonImmutable $momento, ?string $codigoGeneracion = null): string
    {
        if ($rangos === [] || count($rangos) > 2000) {
            throw new DteException('El evento debe tener entre 1 y 2,000 rangos.');
        }

        $cuerpo = [];
        $s = ['noSuj' => Decimal::zero(), 'exenta' => Decimal::zero(), 'gravada' => Decimal::zero()];
        foreach (array_values($rangos) as $i => $r) {
            $n = $i + 1;
            if (! in_array($r['tipoDocumento'], ['02', '97'], true)) {
                throw new DteException("Rango {$n}: tipo de documento inválido (CAT-023: 02 venta simplificada, 97 control interno).");
            }
            if ($r['cantidad'] < 1) {
                throw new DteException("Rango {$n}: la cantidad de documentos debe ser al menos 1.");
            }
            if (! $this->plazos->dentroDelPlazo($momento, $this->plazos->limiteOperacionesEspeciales(CarbonImmutable::parse($r['fechaEmision'], Plazos::zona())))) {
                throw new DteException("Rango {$n}: FECHA FUERA DE PLAZO PERMITIDO (hasta el 10.º día hábil del mes siguiente).");
            }
            $v = fn (string $c) => Decimal::of($r[$c] ?? '0')->round(8);
            [$noSuj, $exenta, $gravada] = [$v('ventaNoSuj'), $v('ventaExenta'), $v('ventaGravada')];
            if ($noSuj->isNegative() || $exenta->isNegative() || $gravada->isNegative()) {
                throw new DteException("Rango {$n}: los montos no pueden ser negativos.");
            }
            $s['noSuj'] = $s['noSuj']->add($noSuj);
            $s['exenta'] = $s['exenta']->add($exenta);
            $s['gravada'] = $s['gravada']->add($gravada);

            $cuerpo[] = [
                'numItem' => $n, 'codigoGeneracionRef' => null, 'tipoDocumento' => $r['tipoDocumento'],
                'numDocumento' => $r['numDocumento'] ?? null, 'fechaEmision' => $r['fechaEmision'],
                'cantidad' => $r['cantidad'], 'descripcion' => $r['descripcion'],
                'docDel' => $r['docDel'] ?? null, 'docAl' => $r['docAl'] ?? null,
                'precioUni' => $v('precioUni'), 'ventaNoSuj' => $noSuj, 'ventaExenta' => $exenta, 'ventaGravada' => $gravada,
                'tributos' => $gravada->isZero() ? null : ['20'],
            ];
        }

        $totalGravada = $s['gravada']->round(2);
        $subTotal = $s['noSuj']->round(2)->add($s['exenta']->round(2))->add($totalGravada);
        $iva = $totalGravada->mul(13)->div(113, 2);
        $ahora = $momento->setTimezone(Plazos::zona());

        return DteJson::encode([
            'identificacion' => [
                'version' => self::VERSION, 'ambiente' => $ambiente, 'tipoModelo' => 1, 'tipoOperacion' => 1,
                'tipoEvento' => self::TIPO_EVENTO, 'codigoGeneracion' => $codigoGeneracion ?? CodigoGeneracion::nuevo(),
                'fecEmi' => $ahora->format('Y-m-d'), 'horEmi' => $ahora->format('H:i:s'), 'tipoMoneda' => 'USD',
            ],
            'emisor' => ['nit' => $empresa->nit, 'nombre' => $empresa->razon_social],
            'cuerpoDocumento' => $cuerpo,
            'resumen' => [
                'totalNoSuj' => $s['noSuj']->round(2), 'totalExenta' => $s['exenta']->round(2), 'totalGravada' => $totalGravada,
                'subTotal' => $subTotal,
                'tributos' => $totalGravada->isZero() ? null : [['codigo' => '20', 'descripcion' => 'Impuesto al Valor Agregado 13%', 'valor' => $iva]],
                'total' => $subTotal,
                'totalLetras' => NumeroALetras::convertir($subTotal),
            ],
            'apendice' => null,
        ]);
    }

    /**
     * @param  list<array{tipoDocumento: string, fechaEmision: string, cantidad: int, descripcion: string, docDel?: string|null, docAl?: string|null, precioUni?: string, ventaNoSuj?: string, ventaExenta?: string, ventaGravada?: string, numDocumento?: string|null}>  $rangos
     */
    public function registrar(Empresa $empresa, EmpresaCredencial $credencial, array $rangos, ?CarbonImmutable $momento = null): Evento
    {
        $momento ??= CarbonImmutable::now(Plazos::zona());
        $evento = $this->firma->firmarYGuardar($empresa, $credencial, Evento::OPERACIONES_ESPECIALES, self::VERSION, $this->armar($empresa, $credencial->ambiente, $rangos, $momento));

        $respuesta = (new HaciendaClient($empresa, $credencial))->recibirDte((string) $evento->jws, self::TIPO_EVENTO, self::VERSION, $evento->codigo_generacion, $evento->id);
        $sello = $respuesta['selloRecibido'] ?? null;
        if (($respuesta['estado'] ?? null) === 'PROCESADO' && is_string($sello) && $sello !== '') {
            $evento->forceFill(['estado' => 'procesado', 'sello_recibido' => $sello, 'respuesta_mh' => $respuesta, 'fh_procesamiento' => now()])->save();

            return $evento;
        }

        $evento->forceFill(['estado' => 'rechazado', 'respuesta_mh' => $respuesta])->save();
        throw new DteException('Hacienda rechazó el evento: '.MensajesHacienda::traducir(
            isset($respuesta['codigoMsg']) ? (string) $respuesta['codigoMsg'] : null,
            isset($respuesta['descripcionMsg']) ? (string) $respuesta['descripcionMsg'] : null,
        ));
    }
}
