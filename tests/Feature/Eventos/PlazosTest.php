<?php

use App\Dte\Eventos\Plazos;
use Carbon\CarbonImmutable;

/*
| Terminado cuando (Paso 7): pruebas de borde de cada plazo (último día válido y primer día inválido).
| Todas en hora de El Salvador.
*/

function sv(string $fecha): CarbonImmutable
{
    return CarbonImmutable::parse($fecha, 'America/El_Salvador');
}

beforeEach(fn () => $this->plazos = new Plazos);

it('cuenta días hábiles sin fines de semana, asuetos ni Jueves/Viernes Santo', function () {
    expect($this->plazos->esHabil(sv('2026-02-13')))->toBeTrue()      // viernes
        ->and($this->plazos->esHabil(sv('2026-02-14')))->toBeFalse()  // sábado
        ->and($this->plazos->esHabil(sv('2026-04-02')))->toBeFalse()  // Jueves Santo 2026
        ->and($this->plazos->esHabil(sv('2026-04-03')))->toBeFalse()  // Viernes Santo 2026
        ->and($this->plazos->esHabil(sv('2026-09-15')))->toBeFalse()  // Independencia
        ->and($this->plazos->esHabil(sv('2027-03-25')))->toBeFalse(); // Jueves Santo 2027 (Pascua 28/03/2027)
});

it('10.º día hábil del mes siguiente (CCF): último día válido y primer día inválido', function (string $tipo) {
    $dte = dteParaPlazos($tipo, '2026-01-20 10:00:00');
    $limite = $this->plazos->limiteInvalidacion($dte);

    expect($limite->format('Y-m-d H:i:s'))->toBe('2026-02-13 23:59:59')
        ->and($this->plazos->dentroDelPlazo(sv('2026-02-13 23:59:59'), $limite))->toBeTrue()
        ->and($this->plazos->dentroDelPlazo(sv('2026-02-14 00:00:00'), $limite))->toBeFalse();
})->with(['03', '04', '05', '06', '07', '08', '09', '15']);

it('el 10.º día hábil salta la Semana Santa', function () {
    $limite = $this->plazos->limiteInvalidacion(dteParaPlazos('03', '2026-03-31 18:00:00'));

    expect($limite->format('Y-m-d'))->toBe('2026-04-16')
        ->and($this->plazos->dentroDelPlazo(sv('2026-04-17 00:00:00'), $limite))->toBeFalse();
});

it('FE, FEX y FSE: 3 meses desde el sello (último día válido y primer día inválido)', function (string $tipo) {
    $limite = $this->plazos->limiteInvalidacion(dteParaPlazos($tipo, '2026-01-31 10:00:00'));

    expect($limite->format('Y-m-d H:i:s'))->toBe('2026-04-30 23:59:59')
        ->and($this->plazos->dentroDelPlazo(sv('2026-04-30 23:59:59'), $limite))->toBeTrue()
        ->and($this->plazos->dentroDelPlazo(sv('2026-05-01 00:00:00'), $limite))->toBeFalse();
})->with(['01', '11', '14']);

it('FE y FEX de medicamentos perecederos: 2 años', function (string $tipo) {
    $limite = $this->plazos->limiteInvalidacion(dteParaPlazos($tipo, '2026-02-15 08:00:00', medicamento: true));

    expect($limite->format('Y-m-d'))->toBe('2028-02-15')
        ->and($this->plazos->dentroDelPlazo(sv('2028-02-15 23:59:59'), $limite))->toBeTrue()
        ->and($this->plazos->dentroDelPlazo(sv('2028-02-16 00:00:00'), $limite))->toBeFalse();
})->with(['01', '11']);

it('la FSE no tiene el plazo de 2 años de medicamentos', function () {
    expect($this->plazos->limiteInvalidacion(dteParaPlazos('14', '2026-02-15 08:00:00', medicamento: true))->format('Y-m-d'))
        ->toBe('2026-05-15');
});

it('retorno: 3 meses desde el sello del original', function () {
    $limite = $this->plazos->limiteRetorno(dteParaPlazos('01', '2026-11-30 12:00:00'));

    expect($limite->format('Y-m-d H:i:s'))->toBe('2027-02-28 23:59:59')
        ->and($this->plazos->dentroDelPlazo(sv('2027-03-01 00:00:00'), $limite))->toBeFalse();
});

it('operaciones especiales: 10.º día hábil del mes siguiente', function () {
    $limite = $this->plazos->limiteOperacionesEspeciales(sv('2026-12-31'));

    // Enero 2027: 1 asueto; 4–8, 11–15 → el 10.º hábil es el viernes 15.
    expect($limite->format('Y-m-d'))->toBe('2027-01-15')
        ->and($this->plazos->dentroDelPlazo(sv('2027-01-16 00:00:00'), $limite))->toBeFalse();
});

it('contingencia: evento dentro de 24 h del cese y documentos dentro de 72 h del sello del evento', function () {
    $cese = sv('2026-11-11 10:00:00');
    $evento = $this->plazos->limiteEventoContingencia($cese);
    $documentos = $this->plazos->limiteTransmisionContingencia(sv('2026-11-11 11:00:00'));

    expect($this->plazos->dentroDelPlazo(sv('2026-11-12 10:00:00'), $evento))->toBeTrue()
        ->and($this->plazos->dentroDelPlazo(sv('2026-11-12 10:00:01'), $evento))->toBeFalse()
        ->and($this->plazos->dentroDelPlazo(sv('2026-11-14 11:00:00'), $documentos))->toBeTrue()
        ->and($this->plazos->dentroDelPlazo(sv('2026-11-14 11:00:01'), $documentos))->toBeFalse();
});
