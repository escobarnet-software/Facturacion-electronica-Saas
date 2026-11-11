<?php

use App\Dte\Eventos\DatosInvalidacion;
use App\Dte\Eventos\Invalidador;
use App\Dte\Eventos\OperacionesEspeciales;
use App\Dte\Eventos\Retornador;
use App\Dte\Excepciones\DteException;
use App\Dte\Validacion\ValidadorEsquema;
use App\Enums\EstadoDte;
use App\Models\Evento;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const URL_ANULAR = 'https://apitest.dtes.mh.gob.sv/fesv/anulardte';
const URL_RECEPCION_EV = 'https://apitest.dtes.mh.gob.sv/fesv/recepciondte';
const URL_AUTH_EV = 'https://apitest.dtes.mh.gob.sv/seguridad/auth';
const SELLO_EV = '20206DFFF66F78D247F7A66D155E68FC7318UADD';

beforeEach(function () {
    Cache::flush();
    $this->d = crearEmpresaDePrueba();
    tenancy()->establecer($this->d['empresa']->id);
    $this->credencial = $this->d['credencial']->fresh();
    $this->empresa = $this->d['empresa']->fresh();
});

function haciendaEventosOk(?array $anular = null, int $status = 200): void
{
    Http::fake([
        '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'eyJhbGciOiJSUzUxMiJ9.ZXZlbnRv.ZmlybWE']),
        URL_AUTH_EV => Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer token.falso']]),
        URL_ANULAR => $anular !== null ? Http::response($anular, $status) : Http::response(['version' => 2, 'ambiente' => '00', 'estado' => 'PROCESADO', 'selloRecibido' => SELLO_EV, 'codigoMsg' => '001', 'descripcionMsg' => 'Invalidación Recibida y Procesada']),
        URL_RECEPCION_EV => Http::response(['version' => 2, 'ambiente' => '00', 'estado' => 'PROCESADO', 'selloRecibido' => SELLO_EV, 'codigoMsg' => '001', 'descripcionMsg' => 'RECIBIDO', 'observaciones' => []]),
    ]);
}

function rescision(?string $reemplazo = null, int $tipo = 2, ?string $motivo = null): DatosInvalidacion
{
    return new DatosInvalidacion($tipo, 'Responsable de Prueba', '13', '012345678', 'Cliente Solicitante', '13', '098765432', $motivo, $reemplazo);
}

function validarEvento(string $json, string $tipo): array
{
    return (new ValidadorEsquema(config('dte.esquemas')))->validar($json, $tipo);
}

// ===================== Invalidación =====================

it('invalida una factura con sello: firma, transmite y deja el original invalidado', function () {
    $dte = sellarDte(crearDteDePrueba($this->d), now('America/El_Salvador')->subDay()->format('Y-m-d H:i:s'));
    haciendaEventosOk();

    $evento = (new Invalidador)->invalidar($dte, $this->empresa, $this->credencial, rescision());

    expect($evento->estado)->toBe('procesado')
        ->and($evento->sello_recibido)->toBe(SELLO_EV)
        ->and($dte->fresh()->estado)->toBe(EstadoDte::Invalidado)
        ->and(validarEvento($evento->json_texto, 'invalidacion'))->toBe([])
        ->and($evento->json_original['documento']['selloRecibido'])->toBe($dte->sello_recibido);

    Http::assertSent(fn (Request $r) => $r->url() === URL_ANULAR && $r['ambiente'] === '00' && is_int($r['idEnvio'])
        && $r['version'] === 3 && $r['documento'] === $evento->jws);
});

it('rechaza invalidar fuera de plazo con «FECHA FUERA DE PLAZO PERMITIDO» (último día válido / primer día inválido)', function () {
    $dte = sellarDte(crearDteDePrueba($this->d), '2026-01-31 10:00:00');
    $dte->forceFill(['fecha_emision' => '2026-01-31'])->save();
    $inv = new Invalidador;

    expect(validarEvento($inv->armar($dte, $this->empresa, rescision(), CarbonImmutable::parse('2026-04-30 23:59:59', 'America/El_Salvador')), 'invalidacion'))->toBe([]);
    expect(fn () => $inv->armar($dte, $this->empresa, rescision(), CarbonImmutable::parse('2026-05-01 00:00:00', 'America/El_Salvador')))
        ->toThrow(DteException::class, 'FECHA FUERA DE PLAZO PERMITIDO');
});

it('solo invalida documentos con sello', function () {
    $dte = crearDteDePrueba($this->d);

    expect(fn () => (new Invalidador)->armar($dte, $this->empresa, rescision(), CarbonImmutable::now()))
        ->toThrow(DteException::class, 'sello');
});

it('por error en la información exige el documento que reemplaza, ya sellado', function () {
    $dte = sellarDte(crearDteDePrueba($this->d), now()->format('Y-m-d H:i:s'));
    $inv = new Invalidador;

    expect(fn () => $inv->armar($dte, $this->empresa, rescision(null, 1), CarbonImmutable::now()))->toThrow(DteException::class, 'Primero emita');

    $sinSello = crearDteDePrueba($this->d);
    expect(fn () => $inv->armar($dte, $this->empresa, rescision($sinSello->codigo_generacion, 1), CarbonImmutable::now()))->toThrow(DteException::class, 'sello');

    $reemplazo = sellarDte(crearDteDePrueba($this->d), now()->format('Y-m-d H:i:s'));
    $json = $inv->armar($dte, $this->empresa, rescision($reemplazo->codigo_generacion, 1), CarbonImmutable::now());
    expect(json_decode($json, true)['documento']['codigoGeneracionR'])->toBe($reemplazo->codigo_generacion);
});

it('la rescisión no lleva documento que reemplaza y el tipo 3 exige motivo', function () {
    $dte = sellarDte(crearDteDePrueba($this->d), now()->format('Y-m-d H:i:s'));
    $otro = sellarDte(crearDteDePrueba($this->d), now()->format('Y-m-d H:i:s'));
    $inv = new Invalidador;

    expect(fn () => $inv->armar($dte, $this->empresa, rescision($otro->codigo_generacion, 2), CarbonImmutable::now()))->toThrow(DteException::class)
        ->and(fn () => $inv->armar($dte, $this->empresa, rescision($otro->codigo_generacion, 3, '  '), CarbonImmutable::now()))->toThrow(DteException::class, 'motivo');
});

it('si Hacienda rechaza la invalidación, el original sigue vigente', function () {
    $dte = sellarDte(crearDteDePrueba($this->d), now()->format('Y-m-d H:i:s'));
    haciendaEventosOk(['estado' => 'RECHAZADO', 'selloRecibido' => null, 'codigoMsg' => '018', 'descripcionMsg' => 'FECHA FUERA DE PLAZO PERMITIDO'], 400);

    expect(fn () => (new Invalidador)->invalidar($dte, $this->empresa, $this->credencial, rescision()))->toThrow(DteException::class, 'FECHA FUERA DE PLAZO');
    expect($dte->fresh()->estado)->toBe(EstadoDte::Transmitido)
        ->and(Evento::query()->value('estado'))->toBe('rechazado');
});

// ===================== Retorno =====================

it('un retorno NO invalida el original: lo deja ajustado', function () {
    $dte = sellarDte(crearDteDePrueba($this->d), now()->format('Y-m-d H:i:s'));
    haciendaEventosOk();

    $evento = (new Retornador)->registrar($dte, $this->empresa, $this->credencial, [['descripcion' => 'Devolución de 2 horchatas', 'cantidad' => '2', 'precio' => '1.00']]);

    expect($evento->estado)->toBe('procesado')
        ->and($dte->fresh()->estado)->toBe(EstadoDte::Ajustado)
        ->and($dte->fresh()->estado)->not->toBe(EstadoDte::Invalidado)
        ->and(validarEvento($evento->json_texto, 'retorno'))->toBe([])
        ->and($evento->json_original['resumen']['totalIva'])->toEqual(0.23);   // 2.00 × 13/113

    Http::assertSent(fn (Request $r) => $r->url() === URL_RECEPCION_EV && $r['tipoDte'] === '18' && $r['version'] === 1);
    // Y el retornado aún puede invalidarse.
    expect((new Invalidador)->armar($dte->fresh(), $this->empresa, rescision(), CarbonImmutable::now()))->toBeString();
});

it('el retorno no puede superar lo vendido, sumando los anteriores', function () {
    $dte = sellarDte(crearDteDePrueba($this->d), now()->format('Y-m-d H:i:s')); // total $6.50
    haciendaEventosOk();
    $ret = new Retornador;

    $ret->registrar($dte, $this->empresa, $this->credencial, [['descripcion' => 'Pupusas', 'cantidad' => '6', 'precio' => '0.75']]); // 4.50
    expect(fn () => $ret->registrar($dte->fresh(), $this->empresa, $this->credencial, [['descripcion' => 'Horchata', 'cantidad' => '2.01', 'precio' => '1.00']]))
        ->toThrow(DteException::class, 'no puede superar lo vendido');
});

it('el retorno solo aplica a FE, FEX y FSE y dentro de 3 meses del sello', function () {
    $ccf = sellarDte(crearDteDeTipo($this->d, '03'), now()->format('Y-m-d H:i:s'));
    $fe = sellarDte(crearDteDePrueba($this->d), '2026-01-31 10:00:00');
    $linea = [['descripcion' => 'X', 'cantidad' => '1', 'precio' => '1']];

    expect(fn () => (new Retornador)->armar($ccf, $this->empresa, $linea, CarbonImmutable::now()))->toThrow(DteException::class, 'Facturas')
        ->and((new Retornador)->armar($fe, $this->empresa, $linea, CarbonImmutable::parse('2026-04-30 23:00:00', 'America/El_Salvador')))->toBeString()
        ->and(fn () => (new Retornador)->armar($fe, $this->empresa, $linea, CarbonImmutable::parse('2026-05-01 00:00:00', 'America/El_Salvador')))->toThrow(DteException::class, 'FECHA FUERA DE PLAZO');
});

it('el retorno de exportación y de sujeto excluido cumple su esquema', function (string $tipo) {
    $dte = sellarDte(crearDteDeTipo($this->d, $tipo), now()->format('Y-m-d H:i:s'));
    $json = (new Retornador)->armar($dte, $this->empresa, [['descripcion' => 'Devolución', 'cantidad' => '1', 'precio' => '10.00']], CarbonImmutable::now());

    expect(validarEvento($json, 'retorno'))->toBe([]);
})->with(['11', '14']);

// ===================== Operaciones especiales =====================

it('arma el evento de operaciones especiales por rangos y cumple su esquema', function () {
    haciendaEventosOk();
    $hoy = now('America/El_Salvador')->format('Y-m-d');

    $evento = (new OperacionesEspeciales)->registrar($this->empresa, $this->credencial, [
        ['tipoDocumento' => '02', 'fechaEmision' => $hoy, 'cantidad' => 50, 'descripcion' => 'Ventas simplificadas', 'docDel' => '1001', 'docAl' => '1050', 'precioUni' => '2.26', 'ventaGravada' => '113.00'],
        ['tipoDocumento' => '97', 'fechaEmision' => $hoy, 'cantidad' => 3, 'descripcion' => 'Control interno', 'ventaExenta' => '15.00'],
    ]);

    expect($evento->estado)->toBe('procesado')
        ->and(validarEvento($evento->json_texto, 'operaciones_especiales'))->toBe([])
        ->and($evento->json_original['resumen']['total'])->toEqual(128)
        ->and($evento->json_original['resumen']['tributos'][0]['valor'])->toEqual(13);
    Http::assertSent(fn (Request $r) => $r->url() === URL_RECEPCION_EV && $r['tipoDte'] === '17');
});

it('operaciones especiales fuera de plazo o con tipo inválido se rechazan', function () {
    $op = new OperacionesEspeciales;

    expect(fn () => $op->armar($this->empresa, '00', [['tipoDocumento' => '02', 'fechaEmision' => '2026-01-10', 'cantidad' => 1, 'descripcion' => 'X', 'ventaGravada' => '1']], CarbonImmutable::parse('2026-02-14', 'America/El_Salvador')))
        ->toThrow(DteException::class, 'FECHA FUERA DE PLAZO')
        ->and(fn () => $op->armar($this->empresa, '00', [['tipoDocumento' => '01', 'fechaEmision' => '2026-01-10', 'cantidad' => 1, 'descripcion' => 'X']], CarbonImmutable::parse('2026-01-20', 'America/El_Salvador')))
        ->toThrow(DteException::class, 'CAT-023');
});
