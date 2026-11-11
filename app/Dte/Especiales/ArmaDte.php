<?php

declare(strict_types=1);

namespace App\Dte\Especiales;

use App\Dte\Catalogos\Catalogo;
use App\Dte\Excepciones\DteException;
use App\Dte\Modelos\Emisor;
use App\Dte\Modelos\Pago;
use App\Dte\Support\CodigoGeneracion;
use App\Dte\Support\NumeroControl;
use App\Support\Decimal;
use DateTimeImmutable;
use DateTimeZone;

/** Piezas comunes de los builders de FSE, FEX, CR y CD. */
trait ArmaDte
{
    /**
     * @param  array<string, mixed>  $extra
     * @return array{0: array<string, mixed>, 1: string, 2: string} [identificacion, numeroControl, codigoGeneracion]
     */
    protected function identificacion(string $tipo, int $version, string $ambiente, Emisor $emisor, int $correlativo, DateTimeImmutable $fechaHora, ?string $codigo, array $extra = [], bool $admiteContingencia = true): array
    {
        if (! Catalogo::existe(Catalogo::AMBIENTE, $ambiente)) {
            throw new DteException("Ambiente inválido: {$ambiente}. Use 00 (pruebas) o 01 (producción).");
        }
        $codigo ??= CodigoGeneracion::nuevo();
        if (! CodigoGeneracion::esValido($codigo)) {
            throw new DteException('Código de generación inválido: debe ser un UUID v4 en mayúsculas.');
        }
        $fecha = $fechaHora->setTimezone(new DateTimeZone('America/El_Salvador'));
        $numeroControl = NumeroControl::generar($tipo, $emisor->codEstable, $emisor->codPuntoVenta, $correlativo);

        $contingencia = $admiteContingencia ? ['tipoContingencia' => null, 'motivoContin' => null] : [];

        return [[
            'version' => $version,
            'ambiente' => $ambiente,
            'tipoDte' => $tipo,
            'numeroControl' => $numeroControl,
            'codigoGeneracion' => $codigo,
            'tipoModelo' => 1,
            'tipoOperacion' => 1,
            ...$contingencia,
            'fecEmi' => $fecha->format('Y-m-d'),
            'horEmi' => $fecha->format('H:i:s'),
            'tipoMoneda' => 'USD',
            ...$extra,
        ], $numeroControl, $codigo];
    }

    /** @return array{departamento: string, municipio: string, distrito: string, complemento: string} */
    protected function direccionEmisor(Emisor $e): array
    {
        return ['departamento' => $e->departamento, 'municipio' => $e->municipio, 'distrito' => $e->distrito, 'complemento' => $e->complemento];
    }

    /**
     * @param  list<Pago>  $pagos
     * @return list<array<string, mixed>>
     */
    protected function pagosQueSuman(array $pagos, Decimal $total, string $que = 'el total'): array
    {
        if ($pagos === []) {
            $pagos = [new Pago('01')];
        }
        $resultado = [];
        $suma = Decimal::zero();
        foreach ($pagos as $pago) {
            if (! Catalogo::existe(Catalogo::FORMA_PAGO, $pago->codigo)) {
                throw new DteException("Forma de pago inválida: {$pago->codigo} (CAT-017).");
            }
            $monto = $pago->monto ?? (count($pagos) === 1 ? $total : null);
            if ($monto === null) {
                throw new DteException('Con varias formas de pago, cada una debe indicar su monto.');
            }
            $monto = $monto->round(2);
            $suma = $suma->add($monto);
            $resultado[] = ['codigo' => $pago->codigo, 'montoPago' => $monto, 'referencia' => $pago->referencia, 'plazo' => $pago->plazo, 'periodo' => $pago->periodo];
        }
        if (! $suma->equals($total)) {
            throw new DteException("Los pagos suman \${$suma->toFixed(2)} pero {$que} es \${$total->toFixed(2)}.");
        }

        return $resultado;
    }

    protected function validarDescripcion(string $descripcion, int $n): void
    {
        $largo = mb_strlen(trim($descripcion));
        if ($largo < 1 || $largo > 1500) {
            throw new DteException("Línea {$n}: la descripción debe tener entre 1 y 1,500 caracteres.");
        }
    }
}
