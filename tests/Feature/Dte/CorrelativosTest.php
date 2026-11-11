<?php

use App\Dte\Correlativos\Correlativos;
use App\Dte\Correlativos\GeneradorNumeroControl;
use App\Dte\Correlativos\ModoCorrelativo;
use App\Dte\Support\NumeroControl;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;

beforeEach(function () {
    $this->d = crearEmpresaDePrueba();
    tenancy()->establecer($this->d['empresa']->id);
    $this->fecha = new DateTimeImmutable('2026-06-15 10:00:00', new DateTimeZone('America/El_Salvador'));
});

function siguienteCorrelativo(array $d, string $tipo, ?Establecimiento $e = null, ?PuntoVenta $p = null, ?DateTimeInterface $fecha = null): int
{
    return app(Correlativos::class)->siguiente(
        $d['empresa'], $tipo, $e ?? $d['establecimiento'], $p ?? $d['puntoVenta'],
        $fecha ?? new DateTimeImmutable('2026-06-15 10:00:00', new DateTimeZone('America/El_Salvador')),
    );
}

function usarModo(array $d, ModoCorrelativo $modo): void
{
    $d['empresa']->forceFill(['modo_correlativo' => $modo->value])->save();
}

it('empieza en 1 y sube de uno en uno', function () {
    expect(siguienteCorrelativo($this->d, '01'))->toBe(1)
        ->and(siguienteCorrelativo($this->d, '01'))->toBe(2)
        ->and(siguienteCorrelativo($this->d, '01'))->toBe(3);
});

it('modo por_tipo: una secuencia por tipo y por punto de venta', function () {
    usarModo($this->d, ModoCorrelativo::PorTipo);
    $caja2 = PuntoVenta::query()->create(['establecimiento_id' => $this->d['establecimiento']->id, 'nombre' => 'Caja 2', 'cod_punto_venta_mh' => 'P002']);

    expect([siguienteCorrelativo($this->d, '01'), siguienteCorrelativo($this->d, '03'), siguienteCorrelativo($this->d, '01'), siguienteCorrelativo($this->d, '01', p: $caja2)])
        ->toBe([1, 1, 2, 1]);
});

it('modo por_establecimiento: compartida por tipos y cajas del establecimiento', function () {
    usarModo($this->d, ModoCorrelativo::PorEstablecimiento);
    $caja2 = PuntoVenta::query()->create(['establecimiento_id' => $this->d['establecimiento']->id, 'nombre' => 'Caja 2', 'cod_punto_venta_mh' => 'P002']);
    $sucursal = Establecimiento::query()->create(['tipo' => '01', 'nombre' => 'Sucursal', 'cod_estable_mh' => 'S001']);
    $cajaSucursal = PuntoVenta::query()->create(['establecimiento_id' => $sucursal->id, 'nombre' => 'Caja', 'cod_punto_venta_mh' => 'P001']);

    expect([
        siguienteCorrelativo($this->d, '01'),
        siguienteCorrelativo($this->d, '03'),
        siguienteCorrelativo($this->d, '01', p: $caja2),
        siguienteCorrelativo($this->d, '01', $sucursal, $cajaSucursal),
    ])->toBe([1, 2, 3, 1]);
});

it('modo centralizado: una sola secuencia para toda la empresa', function () {
    usarModo($this->d, ModoCorrelativo::Centralizado);
    $sucursal = Establecimiento::query()->create(['tipo' => '01', 'nombre' => 'Sucursal', 'cod_estable_mh' => 'S001']);
    $cajaSucursal = PuntoVenta::query()->create(['establecimiento_id' => $sucursal->id, 'nombre' => 'Caja', 'cod_punto_venta_mh' => 'P001']);

    expect([siguienteCorrelativo($this->d, '01'), siguienteCorrelativo($this->d, '03'), siguienteCorrelativo($this->d, '05', $sucursal, $cajaSucursal)])
        ->toBe([1, 2, 3]);
});

it('el cambio de año reinicia el correlativo en 1 (hora de El Salvador)', function () {
    $sv = new DateTimeZone('America/El_Salvador');
    siguienteCorrelativo($this->d, '01', fecha: new DateTimeImmutable('2026-12-31 08:00:00', $sv));
    $ultimoDelAnio = siguienteCorrelativo($this->d, '01', fecha: new DateTimeImmutable('2026-12-31 23:59:59', $sv));

    // 2027-01-01 05:30 UTC todavía es 31 de diciembre (23:30) en El Salvador.
    $casiMedianoche = siguienteCorrelativo($this->d, '01', fecha: new DateTimeImmutable('2027-01-01 05:30:00', new DateTimeZone('UTC')));
    $primeroDeEnero = siguienteCorrelativo($this->d, '01', fecha: new DateTimeImmutable('2027-01-01 00:00:00', $sv));

    expect([$ultimoDelAnio, $casiMedianoche, $primeroDeEnero, siguienteCorrelativo($this->d, '01', fecha: new DateTimeImmutable('2027-01-01 00:00:01', $sv))])
        ->toBe([2, 3, 1, 2]);
});

it('genera el número de control oficial de 31 caracteres', function () {
    $r = app(GeneradorNumeroControl::class)->siguiente($this->d['empresa'], '01', $this->d['establecimiento'], $this->d['puntoVenta'], $this->fecha);

    expect($r)->toBe(['correlativo' => 1, 'numero_control' => 'DTE-01-M001P001-000000000000001'])
        ->and(strlen($r['numero_control']))->toBe(31)
        ->and(NumeroControl::esValido($r['numero_control'], '01'))->toBeTrue();
});

it('no asigna correlativos para una empresa que no es la activa', function () {
    $otra = crearEmpresaDePrueba();
    tenancy()->establecer($this->d['empresa']->id);

    expect(fn () => siguienteCorrelativo($otra, '01'))->toThrow(LogicException::class);
});

it('un número entregado no se reutiliza aunque el documento falle después', function () {
    $entregado = siguienteCorrelativo($this->d, '01');

    try {
        DB::transaction(fn () => throw new RuntimeException('falla la validación local del documento'));
    } catch (RuntimeException) {
    }

    expect(siguienteCorrelativo($this->d, '01'))->toBe($entregado + 1);
});
