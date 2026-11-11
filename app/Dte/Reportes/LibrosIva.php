<?php

declare(strict_types=1);

namespace App\Dte\Reportes;

use App\Enums\EstadoDte;
use App\Models\Dte;
use App\Models\DteRecibido;
use App\Support\Decimal;
use Illuminate\Support\Collection;

/**
 * Libros de IVA y resumen para la declaración (F-07), a partir de los DTE de la empresa activa.
 *
 *  - Cuentan los documentos vigentes con sello o en contingencia (transmitido, observado, ajustado,
 *    transitorio). No cuentan los invalidados, rechazados ni borradores.
 *  - Las Notas de Crédito restan; las de Débito suman.
 *  - Todo con Decimal (NUMERIC de PostgreSQL leído como texto): nunca float.
 *
 * TODO(normativa): ajustar columnas al formato oficial de los anexos del F-07 cuando se tenga
 * el instructivo vigente de Hacienda ([VERIFICAR] en ESPECIFICACION §19.1.9).
 */
final class LibrosIva
{
    public const VIGENTES = [EstadoDte::Transmitido, EstadoDte::Observado, EstadoDte::Ajustado, EstadoDte::Transitorio];

    /**
     * Libro de ventas a consumidor final: una fila por día (FE y FEX).
     *
     * @return array{columnas: list<string>, filas: list<list<string>>, totales: array<string, string>}
     */
    public function ventasConsumidor(string $desde, string $hasta): array
    {
        $porDia = $this->emitidos($desde, $hasta, ['01', '11'])->groupBy(fn (Dte $d) => $d->fecha_emision->format('Y-m-d'))->sortKeys();
        $filas = [];
        $t = ['exentas' => Decimal::zero(), 'no_sujetas' => Decimal::zero(), 'gravadas' => Decimal::zero(), 'exportaciones' => Decimal::zero(), 'total' => Decimal::zero()];

        foreach ($porDia as $fecha => $docs) {
            $fe = $docs->where('tipo', '01');
            $fila = [
                'exentas' => $this->suma($fe, 'total_exenta'),
                'no_sujetas' => $this->suma($fe, 'total_no_suj'),
                'gravadas' => $this->suma($fe, 'total_gravada'),
                'exportaciones' => $this->suma($docs->where('tipo', '11'), 'total_pagar'),
            ];
            $fila['total'] = $fila['exentas']->add($fila['no_sujetas'])->add($fila['gravadas'])->add($fila['exportaciones']);
            foreach ($fila as $k => $v) {
                $t[$k] = $t[$k]->add($v);
            }
            $controles = $docs->pluck('numero_control')->sort()->values();
            $filas[] = [
                substr((string) $fecha, 8, 2).'/'.substr((string) $fecha, 5, 2).'/'.substr((string) $fecha, 0, 4),
                (string) $controles->first(), (string) $controles->last(), (string) $docs->count(),
                ...array_map(fn (Decimal $v) => $v->toFixed(2), array_values($fila)),
            ];
        }

        return [
            'columnas' => ['Fecha', 'Del número de control', 'Al número de control', 'Documentos', 'Ventas exentas', 'Ventas no sujetas', 'Ventas gravadas (IVA incluido)', 'Exportaciones', 'Total'],
            'filas' => $filas,
            'totales' => array_map(fn (Decimal $v) => $v->toFixed(2), $t),
        ];
    }

    /**
     * Libro de ventas a contribuyentes: un renglón por CCF, NC y ND (NC en negativo).
     *
     * @return array{columnas: list<string>, filas: list<list<string>>, totales: array<string, string>}
     */
    public function ventasContribuyentes(string $desde, string $hasta): array
    {
        $filas = [];
        $t = ['exentas' => Decimal::zero(), 'no_sujetas' => Decimal::zero(), 'gravadas' => Decimal::zero(), 'debito' => Decimal::zero(), 'percibido' => Decimal::zero(), 'retenido' => Decimal::zero(), 'total' => Decimal::zero()];

        foreach ($this->emitidos($desde, $hasta, ['03', '05', '06']) as $d) {
            $signo = $d->tipo === '05' ? '-1' : '1';
            $r = $d->json_original['resumen'] ?? [];
            $v = [
                'exentas' => Decimal::of((string) $d->getRawOriginal('total_exenta'))->mul($signo),
                'no_sujetas' => Decimal::of((string) $d->getRawOriginal('total_no_suj'))->mul($signo),
                'gravadas' => Decimal::of((string) $d->getRawOriginal('total_gravada'))->mul($signo),
                'debito' => Decimal::of((string) $d->getRawOriginal('total_iva'))->mul($signo),
                'percibido' => Decimal::of($this->texto($r['ivaPerci'] ?? 0))->mul($signo),
                'retenido' => Decimal::of($this->texto($r['ivaRete'] ?? 0))->mul($signo),
                'total' => Decimal::of((string) $d->getRawOriginal('total_pagar'))->mul($signo),
            ];
            foreach ($v as $k => $x) {
                $t[$k] = $t[$k]->add($x);
            }
            $receptor = $d->json_original['receptor'] ?? [];
            $filas[] = [
                $d->fecha_emision->format('d/m/Y'), $d->tipo, $d->numero_control, $d->codigo_generacion,
                (string) ($receptor['nrc'] ?? ''), (string) ($receptor['nit'] ?? $receptor['numDocumento'] ?? ''), (string) $d->receptor_nombre,
                ...array_map(fn (Decimal $x) => $x->toFixed(2), array_values($v)),
            ];
        }

        return [
            'columnas' => ['Fecha', 'Tipo', 'Número de control', 'Código de generación', 'NRC cliente', 'NIT cliente', 'Cliente', 'Ventas exentas', 'Ventas no sujetas', 'Ventas gravadas', 'Débito fiscal', 'IVA percibido', 'IVA retenido', 'Total'],
            'filas' => $filas,
            'totales' => array_map(fn (Decimal $v) => $v->toFixed(2), $t),
        ];
    }

    /**
     * Libro de compras: DTE recibidos (CCF, NC, ND) y compras a sujetos excluidos (FSE emitidas).
     *
     * @return array{columnas: list<string>, filas: list<list<string>>, totales: array<string, string>}
     */
    public function compras(string $desde, string $hasta): array
    {
        $filas = [];
        $t = ['exentas' => Decimal::zero(), 'gravadas' => Decimal::zero(), 'credito' => Decimal::zero(), 'excluidos' => Decimal::zero(), 'total' => Decimal::zero()];

        $recibidos = DteRecibido::query()->whereIn('tipo', ['03', '05', '06'])->whereBetween('fecha_emision', [$desde, $hasta])->orderBy('fecha_emision')->orderBy('id')->get();
        foreach ($recibidos as $c) {
            $signo = $c->tipo === '05' ? '-1' : '1';
            $v = [
                'exentas' => Decimal::of((string) $c->getRawOriginal('total_exenta'))->add((string) $c->getRawOriginal('total_no_suj'))->mul($signo),
                'gravadas' => Decimal::of((string) $c->getRawOriginal('total_gravada'))->mul($signo),
                'credito' => Decimal::of((string) $c->getRawOriginal('total_iva'))->mul($signo),
                'excluidos' => Decimal::zero(),
                'total' => Decimal::of((string) $c->getRawOriginal('total_pagar'))->mul($signo),
            ];
            foreach ($v as $k => $x) {
                $t[$k] = $t[$k]->add($x);
            }
            $filas[] = [$c->fecha_emision->format('d/m/Y'), $c->tipo, $c->numero_control, $c->codigo_generacion, (string) $c->emisor_nrc, $c->emisor_nit, $c->emisor_nombre, ...array_map(fn (Decimal $x) => $x->toFixed(2), array_values($v))];
        }

        foreach ($this->emitidos($desde, $hasta, ['14']) as $f) {
            $compra = Decimal::of($this->texto($f->json_original['resumen']['subTotal'] ?? 0));
            $t['excluidos'] = $t['excluidos']->add($compra);
            $t['total'] = $t['total']->add($compra);
            $sujeto = $f->json_original['receptor'] ?? [];
            $filas[] = [$f->fecha_emision->format('d/m/Y'), '14', $f->numero_control, $f->codigo_generacion, '', (string) ($sujeto['numDocumento'] ?? ''), (string) ($sujeto['nombre'] ?? ''), '0.00', '0.00', '0.00', $compra->toFixed(2), $compra->toFixed(2)];
        }

        return [
            'columnas' => ['Fecha', 'Tipo', 'Número de control', 'Código de generación', 'NRC proveedor', 'NIT proveedor', 'Proveedor', 'Compras exentas y no sujetas', 'Compras gravadas', 'Crédito fiscal', 'Compras a sujetos excluidos', 'Total'],
            'filas' => $filas,
            'totales' => array_map(fn (Decimal $v) => $v->toFixed(2), $t),
        ];
    }

    /**
     * Retenciones y percepciones del período: CR emitidos (como agente) y recibidos (como sujeto retenido),
     * más la retención/percepción del 1 % en CCF, NC y ND.
     *
     * @return array<string, string>
     */
    public function retencionesPercepciones(string $desde, string $hasta): array
    {
        $crEmitidos = $this->emitidos($desde, $hasta, ['07'])->reduce(fn (Decimal $s, Dte $d) => $s->add($this->texto($d->json_original['resumen']['totalIvaRetenido'] ?? 0)), Decimal::zero());
        $crRecibidos = DteRecibido::query()->where('tipo', '07')->whereBetween('fecha_emision', [$desde, $hasta])->get()
            ->reduce(fn (Decimal $s, DteRecibido $r) => $s->add((string) $r->getRawOriginal('iva_retenido')), Decimal::zero());
        $contrib = $this->ventasContribuyentes($desde, $hasta)['totales'];
        $percibidoCompras = DteRecibido::query()->whereIn('tipo', ['03', '05', '06'])->whereBetween('fecha_emision', [$desde, $hasta])->get()
            ->reduce(fn (Decimal $s, DteRecibido $r) => $s->add(Decimal::of((string) $r->getRawOriginal('iva_percibido'))->mul($r->tipo === '05' ? '-1' : '1')), Decimal::zero());

        return [
            'retenciones_efectuadas' => $crEmitidos->toFixed(2),
            'retenciones_sufridas' => $crRecibidos->add($contrib['retenido'])->toFixed(2),
            'percepciones_efectuadas' => $contrib['percibido'],
            'percepciones_sufridas' => $percibidoCompras->toFixed(2),
        ];
    }

    /**
     * Resumen para la declaración de IVA (F-07).
     *
     * @return array<string, string>
     */
    public function resumenF07(string $desde, string $hasta): array
    {
        $consumidor = $this->ventasConsumidor($desde, $hasta)['totales'];
        $contrib = $this->ventasContribuyentes($desde, $hasta)['totales'];
        $compras = $this->compras($desde, $hasta)['totales'];
        $ret = $this->retencionesPercepciones($desde, $hasta);

        $ivaFe = $this->suma($this->emitidos($desde, $hasta, ['01']), 'total_iva');
        $baseFe = Decimal::of($consumidor['gravadas'])->sub($ivaFe);
        $debito = $ivaFe->add($contrib['debito']);
        $credito = Decimal::of($compras['credito']);
        $impuesto = $debito->sub($credito)->sub($ret['percepciones_sufridas'])->sub($ret['retenciones_sufridas']);

        return [
            'ventas_gravadas_consumidor_base' => $baseFe->toFixed(2),
            'debito_fiscal_consumidor' => $ivaFe->toFixed(2),
            'ventas_gravadas_contribuyentes' => $contrib['gravadas'],
            'debito_fiscal_contribuyentes' => $contrib['debito'],
            'ventas_exentas_y_no_sujetas' => Decimal::of($consumidor['exentas'])->add($consumidor['no_sujetas'])->add($contrib['exentas'])->add($contrib['no_sujetas'])->toFixed(2),
            'exportaciones' => $consumidor['exportaciones'],
            'total_debito_fiscal' => $debito->toFixed(2),
            'compras_gravadas' => $compras['gravadas'],
            'credito_fiscal' => $credito->toFixed(2),
            'compras_sujetos_excluidos' => $compras['excluidos'],
            'percepciones_sufridas' => $ret['percepciones_sufridas'],
            'retenciones_sufridas' => $ret['retenciones_sufridas'],
            'impuesto_a_pagar' => $impuesto->isNegative() ? '0.00' : $impuesto->toFixed(2),
            'remanente_credito' => $impuesto->isNegative() ? $impuesto->negate()->toFixed(2) : '0.00',
        ];
    }

    /**
     * @param  list<string>  $tipos
     * @return Collection<int, Dte>
     */
    private function emitidos(string $desde, string $hasta, array $tipos): Collection
    {
        return Dte::query()->whereIn('tipo', $tipos)->whereBetween('fecha_emision', [$desde, $hasta])
            ->whereIn('estado', array_map(fn (EstadoDte $e) => $e->value, self::VIGENTES))
            ->orderBy('fecha_emision')->orderBy('numero_control')->get();
    }

    /** @param Collection<int, Dte> $docs */
    private function suma(Collection $docs, string $columna): Decimal
    {
        return $docs->reduce(fn (Decimal $s, Dte $d) => $s->add((string) $d->getRawOriginal($columna)), Decimal::zero());
    }

    private function texto(mixed $numero): string
    {
        return is_float($numero) ? (string) json_encode($numero) : (string) $numero;
    }
}
