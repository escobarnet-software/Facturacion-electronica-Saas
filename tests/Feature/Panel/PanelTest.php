<?php

use App\Dte\Representacion\ArchivoDte;
use App\Enums\EstadoDte;
use App\Enums\Rol;
use App\Livewire\Panel\Caja;
use App\Livewire\Panel\Clientes;
use App\Livewire\Panel\Documentos;
use App\Livewire\Panel\Emitir;
use App\Livewire\Panel\Establecimientos;
use App\Livewire\Panel\Invalidaciones;
use App\Livewire\Panel\Productos;
use App\Models\Cliente;
use App\Models\Contingencia;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\Usuario;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;

/*
| Terminado cuando (Paso 9): pruebas de Livewire emiten FE y CCF de punta a punta; el POS emite y,
| sin conexión, deja el documento en contingencia; los listados filtran y exportan a Excel.
| Hacienda y el firmador siempre simulados (Http::fake).
*/

const MH_PANEL = 'https://apitest.dtes.mh.gob.sv';

beforeEach(function () {
    Cache::flush();
    $this->d = crearEmpresaDePrueba(Rol::Contador);
    tenancy()->establecer($this->d['empresa']->id);
    $this->usuario = $this->d['usuario'];
    $this->actingAs($this->usuario);
    $this->mhCaido = false;

    Http::fake([
        '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'eyJhbGciOiJSUzUxMiJ9.cGFuZWw.ZmlybWE']),
        MH_PANEL.'/seguridad/auth' => fn () => $this->mhCaido ? throw new ConnectionException('timeout') : Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer t']]),
        MH_PANEL.'/fesv/recepciondte' => fn (Request $r) => $this->mhCaido ? throw new ConnectionException('timeout') : Http::response([
            'version' => 2, 'ambiente' => '00', 'estado' => 'PROCESADO', 'codigoGeneracion' => $r['codigoGeneracion'],
            'selloRecibido' => strtoupper(substr(sha1($r['codigoGeneracion']), 0, 40)), 'fhProcesamiento' => '11/11/2026 10:00:00',
            'clasificaMsg' => '10', 'codigoMsg' => '001', 'descripcionMsg' => 'RECIBIDO', 'observaciones' => [],
        ]),
        MH_PANEL.'/fesv/recepcion/consultadte' => fn () => throw new ConnectionException('timeout'),
    ]);
});

function clienteContribuyente(): Cliente
{
    return Cliente::query()->create([
        'tipo_documento' => '36', 'num_documento' => '06140101851014', 'nrc' => '765432', 'nombre' => 'CLIENTE CONTRIBUYENTE, S.A. DE C.V.',
        'cod_actividad' => '46900', 'desc_actividad' => 'Venta al por mayor de otros productos', 'correo' => 'compras@cliente.test',
        'direccion' => ['departamento' => '05', 'municipio' => '25', 'distrito' => '01', 'complemento' => 'Santa Tecla'],
    ]);
}

it('emite una Factura de punta a punta: calcula, firma, transmite y queda con sello', function () {
    Livewire::test(Emitir::class)
        ->set('tipo', '01')
        ->set('lineas', [['descripcion' => 'Pupusa revuelta', 'cantidad' => '6', 'precio' => '0.66371681', 'descuento' => '0', 'tipoVenta' => 'gravada', 'codigo' => null, 'tipoItem' => 1, 'uniMedida' => 59]])
        ->assertSee('Total a pagar')
        ->call('emitir')
        ->assertHasNoErrors()
        ->assertSet('error', null)
        ->assertSee('emitido');

    $dte = Dte::query()->sole();
    expect($dte->tipo)->toBe('01')
        ->and($dte->estado)->toBe(EstadoDte::Transmitido)
        ->and($dte->sello_recibido)->toHaveLength(40)
        ->and($dte->getRawOriginal('total_pagar'))->toBe('4.50')        // 6 × (0.66371681 × 1.13 = 0.75000000)
        ->and($dte->numero_control)->toBe('DTE-01-M001P001-000000000000001');
});

it('emite un Crédito Fiscal de punta a punta con cliente del catálogo', function () {
    $cliente = clienteContribuyente();

    Livewire::test(Emitir::class)
        ->set('tipo', '03')
        ->set('clienteId', $cliente->id)
        ->set('lineas', [['descripcion' => 'Mercadería', 'cantidad' => '25', 'precio' => '250.00', 'descuento' => '20.00', 'tipoVenta' => 'gravada', 'codigo' => null, 'tipoItem' => 1, 'uniMedida' => 59]])
        ->assertSee('$7,039.90')
        ->call('emitir')
        ->assertSet('error', null);

    $dte = Dte::query()->sole();
    expect($dte->tipo)->toBe('03')
        ->and($dte->estado)->toBe(EstadoDte::Transmitido)
        ->and($dte->getRawOriginal('total_pagar'))->toBe('7039.90')
        ->and($dte->getRawOriginal('total_iva'))->toBe('809.90')
        ->and($dte->receptor_nombre)->toBe('CLIENTE CONTRIBUYENTE, S.A. DE C.V.');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/fesv/recepciondte') && $r['tipoDte'] === '03' && $r['version'] === 4);
});

it('un Crédito Fiscal sin cliente contribuyente se rechaza en pantalla, sin consumir correlativo', function () {
    Livewire::test(Emitir::class)
        ->set('tipo', '03')
        ->set('lineas', [['descripcion' => 'X', 'cantidad' => '1', 'precio' => '1', 'descuento' => '0', 'tipoVenta' => 'gravada', 'codigo' => null, 'tipoItem' => 1, 'uniMedida' => 59]])
        ->call('emitir')
        ->assertSet('error', fn ($e) => str_contains((string) $e, 'NRC'));

    expect(Dte::query()->count())->toBe(0)
        ->and(Correlativo::query()->count())->toBe(0);
});

it('agrega productos del catálogo a la emisión', function () {
    $p = Producto::query()->create(['codigo' => 'P-01', 'descripcion' => 'Horchata', 'precio' => '0.88495575']);

    Livewire::test(Emitir::class)->call('agregarProducto', $p->id)
        ->assertSet('lineas.0.descripcion', 'Horchata')
        ->assertSet('lineas.0.precio', '0.88495575');
});

it('POS: escanea, cobra y transmite', function () {
    Producto::query()->create(['codigo' => 'P-01', 'codigo_barras' => '7401234567890', 'descripcion' => 'Café', 'precio' => '1.76991150']);

    Livewire::test(Caja::class)
        ->set('codigo', '7401234567890')->call('escanear')
        ->set('codigo', '7401234567890')->call('escanear')
        ->assertSet('carrito.0.cantidad', '2')
        ->assertSee('Total $4.00')
        ->call('cobrar')
        ->assertSet('error', null)
        ->assertSee('transmitida');

    expect(Dte::query()->sole()->estado)->toBe(EstadoDte::Transmitido);
});

it('POS sin conexión con Hacienda: el documento queda en contingencia y se entrega igual', function () {
    $this->mhCaido = true;
    Producto::query()->create(['codigo' => 'P-01', 'descripcion' => 'Café', 'precio' => '1.00']);

    Livewire::test(Caja::class)->set('codigo', 'P-01')->call('escanear')->call('cobrar')
        ->assertSet('error', null)
        ->assertSee('contingencia');

    $dte = Dte::query()->sole();
    expect($dte->estado)->toBe(EstadoDte::Transitorio)
        ->and($dte->operacion)->toBe(2)
        ->and(Contingencia::query()->where('estado', Contingencia::ACTIVA)->count())->toBe(1);
});

it('el listado filtra por tipo, estado, receptor y código, y exporta a Excel', function () {
    $cliente = clienteContribuyente();
    $fe = crearDteDePrueba($this->d);
    sellarDte($fe, now()->format('Y-m-d H:i:s'));
    $ccf = crearDteDeTipo($this->d, '03');
    $ccf->forceFill(['receptor_nombre' => $cliente->nombre, 'receptor_documento' => $cliente->num_documento, 'estado' => 'rechazado'])->save();

    Livewire::test(Documentos::class)
        ->assertSee($fe->numero_control)->assertSee($ccf->numero_control)
        ->set('tipo', '03')->assertSee($ccf->numero_control)->assertDontSee($fe->numero_control)
        ->set('tipo', null)->set('estado', 'transmitido')->assertSee($fe->numero_control)->assertDontSee($ccf->numero_control)
        ->set('estado', null)->set('receptor', 'contribuyente')->assertSee($ccf->numero_control)->assertDontSee($fe->numero_control)
        ->set('receptor', null)->set('codigo', strtolower($fe->codigo_generacion))->assertSee($fe->numero_control)->assertDontSee($ccf->numero_control);

    $respuesta = Livewire::test(Documentos::class)->set('tipo', '03')->call('exportar');
    $respuesta->assertFileDownloaded();
    $contenido = base64_decode($respuesta->effects['download']['content']);
    $archivo = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($archivo, $contenido);
    $reader = new Reader;
    $reader->open($archivo);
    $filas = [];
    foreach ($reader->getSheetIterator() as $hoja) {
        foreach ($hoja->getRowIterator() as $fila) {
            $filas[] = $fila->toArray();
        }
    }
    $reader->close();
    unlink($archivo);

    expect($filas)->toHaveCount(2)
        ->and($filas[0][1])->toBe('Número de control')
        ->and($filas[1][1])->toBe($ccf->numero_control)
        ->and($filas[1][5])->toBe('CLIENTE CONTRIBUYENTE, S.A. DE C.V.');
});

it('el cajero solo ve lo emitido en su punto de venta y no administra catálogos', function () {
    $cajero = Usuario::factory()->create();
    $otraCaja = PuntoVenta::query()->create(['establecimiento_id' => $this->d['establecimiento']->id, 'nombre' => 'Caja 2', 'cod_punto_venta_mh' => 'P002']);
    $this->d['empresa']->usuarios()->attach($cajero->id, ['rol' => Rol::Cajero->value, 'punto_venta_id' => $otraCaja->id]);
    $propio = crearDteDePrueba($this->d);
    $propio->forceFill(['punto_venta_id' => $otraCaja->id])->save();
    $ajeno = crearDteDePrueba($this->d);

    $this->actingAs($cajero);
    tenancy()->fijarUsuario($cajero->id);

    Livewire::test(Documentos::class)->assertSee($propio->numero_control)->assertDontSee($ajeno->numero_control);
    $this->get(route('panel.productos'))->assertForbidden();
    $this->get(route('panel.invalidaciones'))->assertForbidden();
    $this->get(route('panel.caja'))->assertOk();
});

it('el rol consulta no puede emitir', function () {
    $consulta = Usuario::factory()->create();
    $this->d['empresa']->usuarios()->attach($consulta->id, ['rol' => Rol::Consulta->value]);
    $this->actingAs($consulta);
    tenancy()->fijarUsuario($consulta->id);

    $this->get(route('panel.emitir'))->assertForbidden();
    $this->get(route('panel.documentos'))->assertOk();
});

it('descarga el PDF y el JSON desde el panel, y no los de otra empresa', function () {
    $dte = sellarDte(crearDteDePrueba($this->d), now()->format('Y-m-d H:i:s'));
    $otra = crearEmpresaDePrueba();
    $ajeno = tenancy()->ejecutarComo($otra['empresa']->id, fn () => sellarDte(crearDteDePrueba($otra), now()->format('Y-m-d H:i:s')));

    $this->get(route('panel.documentos.pdf', $dte))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('panel.documentos.pdf', ['dte' => $dte->id, 'formato' => 'ticket']))->assertOk();
    expect($this->get(route('panel.documentos.json', $dte))->getContent())->toBe(ArchivoDte::contenido($dte));
    $this->get(route('panel.documentos.pdf', $ajeno->id))->assertNotFound();
});

it('administra productos, clientes, establecimientos y puntos de venta', function () {
    Livewire::test(Productos::class)
        ->set('form.codigo', 'PUP-1')->set('form.descripcion', 'Pupusa de queso')->set('form.precio', '0.66371681')->call('guardar')->assertHasNoErrors();
    Livewire::test(Productos::class)
        ->set('form.codigo', 'PUP-1')->set('form.descripcion', 'Repetido')->set('form.precio', '1')->call('guardar')->assertHasErrors('form.codigo');
    Livewire::test(Productos::class)->set('form.descripcion', 'Malo')->set('form.precio', '1.5.0')->call('guardar')->assertHasErrors('form.precio');

    Livewire::test(Clientes::class)
        ->set('form.num_documento', '06140101851014')->set('form.nombre', 'Cliente Nuevo')->set('form.nrc', '12345')
        ->set('form.departamento', '06')->set('form.municipio', '23')->set('form.distrito', '14')->set('form.complemento', 'San Salvador')
        ->call('guardar')->assertHasNoErrors()
        ->assertSee('••••••••••1014');
    Livewire::test(Clientes::class)->set('form.num_documento', '123')->set('form.nombre', 'X')->call('guardar')->assertHasErrors('form.num_documento');

    Livewire::test(Establecimientos::class)
        ->set('establecimiento.nombre', 'Sucursal Centro')->set('establecimiento.cod_estable_mh', 'S001')->call('crearEstablecimiento')->assertHasNoErrors();
    $sucursal = Establecimiento::query()->where('cod_estable_mh', 'S001')->sole();
    Livewire::test(Establecimientos::class)
        ->set('puntoVenta.establecimiento_id', $sucursal->id)->set('puntoVenta.nombre', 'Caja 1')->set('puntoVenta.cod_punto_venta_mh', 'P001')->call('crearPuntoVenta')->assertHasNoErrors();
    Livewire::test(Establecimientos::class)->set('establecimiento.nombre', 'Mal')->set('establecimiento.cod_estable_mh', 'X1')->call('crearEstablecimiento')->assertHasErrors();

    expect(Producto::query()->sole()->precio)->toBe('0.66371681')
        ->and(Cliente::query()->sole()->direccion)->toEqual(['departamento' => '06', 'municipio' => '23', 'distrito' => '14', 'complemento' => 'San Salvador'])
        ->and(PuntoVenta::query()->where('establecimiento_id', $sucursal->id)->count())->toBe(1);
});

it('invalida desde el panel con el asistente', function () {
    $dte = sellarDte(crearDteDePrueba($this->d), now()->format('Y-m-d H:i:s'));
    Http::fake([MH_PANEL.'/fesv/anulardte' => Http::response(['estado' => 'PROCESADO', 'selloRecibido' => str_repeat('C', 40), 'codigoMsg' => '001'])]);

    Livewire::test(Invalidaciones::class)
        ->assertSee($dte->numero_control)
        ->set('dteId', $dte->id)->set('tipoAnulacion', 2)
        ->set('nombreSolicita', 'Cliente Solicitante')->set('numDocSolicita', '012345678')
        ->call('invalidar')
        ->assertSet('error', null)
        ->assertSee('invalidado');

    expect($dte->fresh()->estado)->toBe(EstadoDte::Invalidado);
});

it('las pantallas del panel cargan', function (string $ruta) {
    $this->get(route($ruta))->assertOk();
})->with(['panel', 'panel.emitir', 'panel.caja', 'panel.documentos', 'panel.invalidaciones', 'panel.contingencia', 'panel.productos', 'panel.clientes', 'panel.establecimientos']);
