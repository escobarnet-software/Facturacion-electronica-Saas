<?php

use App\Dte\Validacion\ValidadorEsquema;
use App\Enums\EstadoDte;
use App\Enums\Rol;
use App\Livewire\Panel\NotaAjuste;
use App\Models\Dte;
use App\Models\Usuario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
| Notas de Crédito (05) y de Débito (06) desde el panel, sobre un Crédito Fiscal con sello.
*/

const MH_AJ = 'https://apitest.dtes.mh.gob.sv';

beforeEach(function () {
    Cache::flush();
    Http::fake([
        '*firmardocumento*' => fn (Request $r) => Http::response(['status' => 'OK', 'body' => 'eyJhbGciOiJSUzUxMiJ9.'.rtrim(strtr(base64_encode(json_encode($r['dteJson'])), '+/', '-_'), '=').'.ZmlybWE']),
        MH_AJ.'/seguridad/auth' => Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer t']]),
        MH_AJ.'/fesv/recepciondte' => fn (Request $r) => Http::response([
            'version' => 2, 'ambiente' => '00', 'estado' => 'PROCESADO', 'codigoGeneracion' => $r['codigoGeneracion'],
            'selloRecibido' => strtoupper(substr(sha1($r['codigoGeneracion']), 0, 40)), 'fhProcesamiento' => '26/11/2026 10:00:00',
            'clasificaMsg' => '10', 'codigoMsg' => '001', 'descripcionMsg' => 'RECIBIDO', 'observaciones' => [],
        ]),
    ]);
    $this->d = crearEmpresaDePrueba(Rol::Contador);
    tenancy()->establecer($this->d['empresa']->id);
    $this->actingAs($this->d['usuario']);
    // Crédito Fiscal de 1 × $100.00 + IVA, con sello.
    $this->ccf = sellarDte(crearDteDeTipo($this->d, '03'), now('America/El_Salvador')->format('Y-m-d H:i:s'));
});

it('emite una Nota de Crédito parcial: líneas precargadas, relacionada al CCF, válida y transmitida', function () {
    $c = Livewire::test(NotaAjuste::class, ['dte' => $this->ccf->id])
        ->assertSet('tipo', '05')->assertSet('lineas.0.precio', '100')
        ->set('lineas.0.precio', '25.00')->set('motivo', 'Descuento posterior por pronto pago')
        ->call('emitir')->assertSet('error', null)->assertSee('Nota de Crédito DTE-05-');

    $nc = Dte::query()->where('tipo', '05')->sole();
    $doc = $nc->json_original;
    expect($nc->estado)->toBe(EstadoDte::Transmitido)
        ->and($doc['documentoRelacionado'][0]['numeroDocumento'])->toBe($this->ccf->codigo_generacion)
        ->and($doc['documentoRelacionado'][0]['tipoDocumento'])->toBe('03')
        ->and($doc['cuerpoDocumento'][0]['numeroDocumento'])->toBe($this->ccf->codigo_generacion)
        ->and($doc['receptor']['numDocumento'])->toBe($this->ccf->json_original['receptor']['nit']) // mismo cliente del CCF
        ->and($nc->getRawOriginal('total_gravada'))->toBe('25.00')
        ->and($nc->getRawOriginal('total_iva'))->toBe('3.25')
        ->and($doc['resumen']['observaciones'])->toBe('Descuento posterior por pronto pago'); // el motivo queda en el documento
    expect((new ValidadorEsquema((string) config('dte.esquemas')))->validar($nc->json_texto, '05'))->toBe([]);
    unset($c);
});

it('la Nota de Crédito no puede superar lo vendido en el Crédito Fiscal, contando las anteriores', function () {
    Livewire::test(NotaAjuste::class, ['dte' => $this->ccf->id])->set('lineas.0.precio', '80')->set('motivo', 'Devolución parcial')
        ->call('emitir')->assertSet('error', null);
    Livewire::test(NotaAjuste::class, ['dte' => $this->ccf->id])->set('lineas.0.precio', '20.01')->set('motivo', 'Otra devolución')
        ->call('emitir')->assertSet('error', 'La Nota de Crédito supera lo vendido en el Crédito Fiscal: disponible para acreditar $20.00.');
    Livewire::test(NotaAjuste::class, ['dte' => $this->ccf->id])->set('lineas.0.precio', '20')->set('motivo', 'Saldo restante')
        ->call('emitir')->assertSet('error', null);

    expect(Dte::query()->where('tipo', '05')->count())->toBe(2);
});

it('emite una Nota de Débito por una diferencia cobrada de menos', function () {
    Livewire::test(NotaAjuste::class, ['dte' => $this->ccf->id, 'tipo' => '06'])->assertSet('tipo', '06')
        ->set('lineas.0.precio', '15')->set('motivo', 'Diferencia de precio no cobrada')
        ->call('emitir')->assertSet('error', null)->assertSee('Nota de Débito DTE-06-');

    $nd = Dte::query()->where('tipo', '06')->sole();
    expect($nd->getRawOriginal('total_gravada'))->toBe('15.00')
        ->and((new ValidadorEsquema((string) config('dte.esquemas')))->validar($nd->json_texto, '06'))->toBe([]);
});

it('valida motivo, cantidades y montos de la nota', function () {
    Livewire::test(NotaAjuste::class, ['dte' => $this->ccf->id])->set('lineas.0.cantidad', '0')->set('motivo', '')
        ->call('emitir')->assertHasErrors(['lineas.0.cantidad', 'motivo' => 'required']);
    Livewire::test(NotaAjuste::class, ['dte' => $this->ccf->id])->call('quitarLinea', 0)->set('motivo', 'Sin líneas')
        ->call('emitir')->assertHasErrors(['lineas' => 'required']);
    expect(Dte::query()->whereIn('tipo', ['05', '06'])->count())->toBe(0);
});

it('solo sobre Créditos Fiscales vigentes con sello, de la propia empresa, y solo para dueño o contador', function () {
    $fe = sellarDte(crearDteDePrueba($this->d), now('America/El_Salvador')->format('Y-m-d H:i:s'));
    Livewire::test(NotaAjuste::class, ['dte' => $fe->id])->assertStatus(404);

    $this->ccf->forceFill(['estado' => EstadoDte::Invalidado])->saveQuietly();
    Livewire::test(NotaAjuste::class, ['dte' => $this->ccf->id])->set('motivo', 'Intento')->call('emitir')
        ->assertSet('error', 'La Nota de Crédito solo puede ajustar un Crédito Fiscal vigente y con sello de Hacienda.');

    $otra = crearEmpresaDePrueba();
    $ajeno = tenancy()->ejecutarComo($otra['empresa']->id, fn () => sellarDte(crearDteDeTipo($otra, '03'), now('America/El_Salvador')->format('Y-m-d H:i:s')));
    tenancy()->establecer($this->d['empresa']->id);
    Livewire::test(NotaAjuste::class, ['dte' => $ajeno->id])->assertStatus(404);

    $cajero = Usuario::factory()->create();
    $this->d['empresa']->usuarios()->attach($cajero->id, ['rol' => Rol::Cajero->value, 'punto_venta_id' => $this->d['puntoVenta']->id]);
    $this->actingAs($cajero);
    $this->get(route('panel.nota', ['dte' => $this->ccf->id]))->assertForbidden();
});

it('Documentos muestra los botones de nota solo en Créditos Fiscales con sello', function () {
    $this->get(route('panel.documentos'))->assertOk()->assertSee('N. crédito')->assertSee('N. débito')
        ->assertSee(route('panel.nota', ['dte' => $this->ccf->id, 'tipo' => '05']), false);
});

it('la API emite una Nota de Crédito sobre un Crédito Fiscal y exige el documento relacionado', function () {
    $token = $this->d['empresa']->createToken('ERP', ['dte:emitir'])->plainTextToken;
    $linea = ['lineas' => [['descripcion' => 'Devolución', 'cantidad' => '1', 'precio' => '10']], 'observaciones' => 'Devolución'];
    tenancy()->limpiar();
    app('auth')->forgetGuards();

    $this->postJson('/api/v1/dte/05', $linea, ['Authorization' => 'Bearer '.$token])->assertStatus(422)->assertJsonValidationErrors('documento_relacionado');
    app('auth')->forgetGuards();
    $r = $this->postJson('/api/v1/dte/05', [...$linea, 'documento_relacionado' => $this->ccf->codigo_generacion], ['Authorization' => 'Bearer '.$token])->assertCreated();
    expect($r->json('data.tipo'))->toBe('05')->and($r->json('data.total_pagar'))->toBe('11.30');
});
