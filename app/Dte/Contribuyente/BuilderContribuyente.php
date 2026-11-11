<?php

declare(strict_types=1);

namespace App\Dte\Contribuyente;

use App\Dte\Catalogos\Catalogo;
use App\Dte\Excepciones\DteException;
use App\Dte\Modelos\Emisor;
use App\Dte\Modelos\Pago;
use App\Dte\Support\CodigoGeneracion;
use App\Dte\Support\NumeroALetras;
use App\Dte\Support\NumeroControl;
use App\Support\Decimal;
use App\Support\DteJson;
use DateTimeZone;

/**
 * Base de los builders de CCF (03), NR (04), NC (05) y ND (06): identificación,
 * emisor, receptor, documentos relacionados y validaciones comunes.
 */
abstract class BuilderContribuyente
{
    abstract protected function tipoDte(): string;

    abstract protected function version(): int;

    /**
     * Arma las secciones propias del tipo (cuerpo, resumen, receptor…).
     *
     * @return array<string, mixed>
     */
    abstract protected function secciones(DocumentoContribuyente $doc, CalculoContribuyente $calculo): array;

    /** @return array<string, mixed> campos adicionales de identificación del tipo */
    protected function identificacionExtra(): array
    {
        return [];
    }

    /** ¿El tipo lleva IVA, percepción y retención por ítem (NC/ND v4)? */
    protected function impuestosPorItem(): bool
    {
        return false;
    }

    public function __construct(
        protected readonly Emisor $emisor,
        protected readonly string $ambiente = '00',
        protected readonly CalculadoraContribuyente $calculadora = new CalculadoraContribuyente,
    ) {
        if (! Catalogo::existe(Catalogo::AMBIENTE, $ambiente)) {
            throw new DteException("Ambiente inválido: {$ambiente}. Use 00 (pruebas) o 01 (producción).");
        }
    }

    public function construir(DocumentoContribuyente $doc, ?string $codigoGeneracion = null): DocumentoArmado
    {
        $codigoGeneracion ??= CodigoGeneracion::nuevo();
        if (! CodigoGeneracion::esValido($codigoGeneracion)) {
            throw new DteException('Código de generación inválido: debe ser un UUID v4 en mayúsculas.');
        }

        $this->validarComun($doc);
        $calculo = $this->calculadora->calcular($doc, $this->impuestosPorItem());

        $fecha = $doc->fechaHora->setTimezone(new DateTimeZone('America/El_Salvador'));
        $numeroControl = NumeroControl::generar($this->tipoDte(), $this->emisor->codEstable, $this->emisor->codPuntoVenta, $doc->correlativo);

        $documento = [
            'identificacion' => [
                'version' => $this->version(),
                'ambiente' => $this->ambiente,
                'tipoDte' => $this->tipoDte(),
                'numeroControl' => $numeroControl,
                'codigoGeneracion' => $codigoGeneracion,
                'tipoModelo' => 1,
                'tipoOperacion' => 1,
                'tipoContingencia' => null,
                'motivoContin' => null,
                'fecEmi' => $fecha->format('Y-m-d'),
                'horEmi' => $fecha->format('H:i:s'),
                'tipoMoneda' => 'USD',
                ...$this->identificacionExtra(),
            ],
            ...$this->secciones($doc, $calculo),
        ];

        return new DocumentoArmado($this->tipoDte(), $this->version(), $codigoGeneracion, $numeroControl, $calculo, DteJson::encode($documento));
    }

    protected function validarComun(DocumentoContribuyente $doc): void
    {
        foreach (array_values($doc->lineas) as $i => $linea) {
            $n = $i + 1;
            $largo = mb_strlen(trim($linea->descripcion));
            if ($largo < 1 || $largo > 1500) {
                throw new DteException("Línea {$n}: la descripción debe tener entre 1 y 1,500 caracteres.");
            }
            if (! in_array($linea->tipoItem, [1, 2, 3, 4], true)) {
                throw new DteException("Línea {$n}: tipo de ítem inválido (CAT-011).");
            }
            if ($linea->tipoItem === 4 && ($linea->codTributo === null || ! preg_match('/^[A-Z0-9]{2}$/', $linea->codTributo))) {
                throw new DteException("Línea {$n}: un ítem de «otros tributos» necesita su código de tributo (CAT-015).");
            }
            if ($linea->uniMedida < 1 || $linea->uniMedida > 99) {
                throw new DteException("Línea {$n}: unidad de medida inválida (CAT-014).");
            }
        }

        if (! Catalogo::existe(Catalogo::CONDICION_OPERACION, $doc->condicionOperacion)) {
            throw new DteException("Condición de operación inválida: {$doc->condicionOperacion} (CAT-016).");
        }
        if (count($doc->relacionados) > 50) {
            throw new DteException('Se pueden relacionar como máximo 50 documentos.');
        }

        $r = $doc->receptor;
        if (! preg_match('/^(\d{9}|\d{14})$/', $r->nit) && $r->tipoDocumento === '36') {
            throw new DteException('El NIT del cliente debe tener 9 o 14 dígitos, sin guiones.');
        }
        if ($r->correo !== null && ! filter_var($r->correo, FILTER_VALIDATE_EMAIL)) {
            throw new DteException("El correo del cliente no es válido: {$r->correo}");
        }
    }

    /** @return array<string, mixed> */
    protected function emisorBase(): array
    {
        $e = $this->emisor;

        return [
            'nit' => $e->nit,
            'nrc' => $e->nrc,
            'nombre' => $e->nombre,
            'codActividad' => $e->codActividad,
            'descActividad' => $e->descActividad,
            'nombreComercial' => $e->nombreComercial,
            'direccion' => ['departamento' => $e->departamento, 'municipio' => $e->municipio, 'distrito' => $e->distrito, 'complemento' => $e->complemento],
            'telefono' => $e->telefono,
            'correo' => $e->correo,
        ];
    }

    /** @return array<string, mixed> */
    protected function emisorConEstablecimiento(): array
    {
        return [...$this->emisorBase(), 'codEstable' => $this->emisor->codEstable, 'codPuntoVenta' => $this->emisor->codPuntoVenta];
    }

    /** @return list<array<string, mixed>>|null */
    protected function relacionados(DocumentoContribuyente $doc): ?array
    {
        return $doc->relacionados === [] ? null : array_map(fn (DocumentoRelacionado $d) => $d->comoArreglo(), $doc->relacionados);
    }

    /** @return list<array{codigo: string, descripcion: string, valor: Decimal}>|null */
    protected function tributosResumen(CalculoContribuyente $c): ?array
    {
        return $c->totalGravada->isZero() ? null : [['codigo' => '20', 'descripcion' => 'Impuesto al Valor Agregado 13%', 'valor' => $c->iva]];
    }

    /**
     * @param  array{ventaGravada: Decimal}  $item
     * @return list<string>|null
     */
    protected function tributosItem(array $item): ?array
    {
        return $item['ventaGravada']->isZero() ? null : ['20'];
    }

    /**
     * @param  list<Pago>  $pagos
     * @return list<array<string, mixed>>
     */
    protected function pagos(array $pagos, Decimal $totalPagar): array
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
            $monto = $pago->monto ?? (count($pagos) === 1 ? $totalPagar : null);
            if ($monto === null) {
                throw new DteException('Con varias formas de pago, cada una debe indicar su monto.');
            }
            $monto = $monto->round(2);
            $suma = $suma->add($monto);
            $resultado[] = ['codigo' => $pago->codigo, 'montoPago' => $monto, 'referencia' => $pago->referencia, 'plazo' => $pago->plazo, 'periodo' => $pago->periodo];
        }

        if (! $suma->equals($totalPagar)) {
            throw new DteException("Los pagos suman \${$suma->toFixed(2)} pero el total es \${$totalPagar->toFixed(2)}.");
        }

        return $resultado;
    }

    protected function letras(Decimal $monto): string
    {
        return NumeroALetras::convertir($monto);
    }
}
