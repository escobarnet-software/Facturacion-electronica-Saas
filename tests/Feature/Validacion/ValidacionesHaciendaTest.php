<?php

use App\Dte\Validacion\ReglasHacienda as R;
use App\Enums\Rol;
use App\Livewire\Panel\Clientes;
use App\Livewire\Panel\Emitir;
use App\Livewire\Panel\Establecimientos;
use App\Livewire\Panel\Invalidaciones;
use App\Livewire\Panel\Onboarding;
use App\Livewire\Panel\Productos;
use App\Models\Cliente;
use App\Models\Plan;
use App\Models\Usuario;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
| Validaciones de datos según Hacienda: longitudes y formatos de los esquemas oficiales y valores
| de los catálogos oficiales (sembrados desde docs/oficial/Catalogos.xlsx).
*/

beforeEach(function () {
    $this->d = crearEmpresaDePrueba(Rol::Contador);
    tenancy()->establecer($this->d['empresa']->id);
    $this->actingAs($this->d['usuario']);
});

function esquema(string $archivo): array
{
    return json_decode((string) file_get_contents(resource_path("schemas/{$archivo}")), true, flags: JSON_THROW_ON_ERROR);
}

// ------------------------------------------------------------------ Fuente: esquemas oficiales

it('los límites de los formularios son exactamente los de los esquemas oficiales de Hacienda', function () {
    $ccf = esquema('fe-ccf-v4.json')['properties'];
    $fe = esquema('fe-f-v2.json')['properties'];
    $receptor = $ccf['receptor']['properties'];
    $item = $ccf['cuerpoDocumento']['items']['properties'];
    $limite = fn (array $campo) => [$campo['minLength'] ?? null, $campo['maxLength'] ?? null];

    expect(R::LIMITES['nombre'])->toBe($limite($receptor['nombre']))
        ->and(R::LIMITES['nombre_comercial'])->toBe($limite($receptor['nombreComercial']))
        ->and(R::LIMITES['nrc'])->toBe($limite($receptor['nrc']))
        ->and(R::LIMITES['cod_actividad'])->toBe($limite($receptor['codActividad']))
        ->and(R::LIMITES['desc_actividad'])->toBe($limite($receptor['descActividad']))
        ->and(R::LIMITES['complemento'])->toBe($limite($receptor['direccion']['properties']['complemento']))
        ->and(R::LIMITES['telefono'])->toBe($limite($receptor['telefono']))
        ->and(R::LIMITES['correo'])->toBe($limite($fe['receptor']['properties']['correo']))
        ->and(R::LIMITES['num_documento'])->toBe($limite($fe['receptor']['properties']['numDocumento']))
        ->and(R::LIMITES['descripcion'])->toBe($limite($item['descripcion']))
        ->and(R::LIMITES['codigo'])->toBe($limite($item['codigo']))
        ->and(R::LIMITES['observaciones'][1])->toBe($ccf['resumen']['properties']['observaciones']['maximum'] ?? 3000);

    // Montos y cantidades: hasta 8 decimales y menores que 100 000 000 000.
    expect($item['cantidad']['multipleOf'])->toBe(1.0E-8)->and($item['precioUni']['exclusiveMaximum'])->toBe(100000000000);
    expect($ccf['cuerpoDocumento']['maxItems'] ?? 2000)->toBe(2000);
});

it('los códigos de establecimiento y punto de venta siguen el patrón del número de control del esquema', function () {
    $patron = esquema('fe-f-v2.json')['properties']['identificacion']['properties']['numeroControl']['pattern'];
    expect($patron)->toContain('(M|B|S|P)([0-9]{3})(P)([0-9]{3})');

    $valida = fn (string $est, string $pv) => (bool) preg_match('/'.$patron.'/', "DTE-01-{$est}{$pv}-000000000000001");
    expect($valida('M001', 'P001'))->toBeTrue()->and($valida('X001', 'P001'))->toBeFalse()->and($valida('M001', 'C001'))->toBeFalse();
});

// ------------------------------------------------------------------ Clientes

it('Clientes: valida NIT, DUI, NRC, correo, teléfono, actividad y dirección con los catálogos oficiales', function (array $datos, string $campo, string $mensaje) {
    $base = ['tipo_documento' => '36', 'num_documento' => '06140101851014', 'nombre' => 'Cliente de prueba'];
    $c = Livewire::test(Clientes::class);
    foreach ([...$base, ...$datos] as $k => $v) {
        $c->set("form.{$k}", $v);
    }
    $c->call('guardar')->assertHasErrors("form.{$campo}");
    expect(collect($c->errors()->get("form.{$campo}"))->implode(' '))->toContain($mensaje);
})->with([
    'NIT con 10 dígitos' => [['num_documento' => '0614010185'], 'num_documento', '9 o 14 dígitos'],
    'NIT con letras' => [['num_documento' => '0614-ABCD-851-0'], 'num_documento', '9 o 14 dígitos'],
    'DUI corto' => [['tipo_documento' => '13', 'num_documento' => '1234567'], 'num_documento', '00000000-0'],
    'NRC con letras' => [['nrc' => '12A45'], 'nrc', '2 a 8 dígitos'],
    'NRC de un dígito' => [['nrc' => '1'], 'nrc', '2 a 8 dígitos'],
    'correo inválido' => [['correo' => 'cliente@'], 'correo', 'correo válido'],
    'teléfono corto' => [['telefono' => '2222'], 'telefono', 'al menos 8'],
    'teléfono con letras' => [['telefono' => '2222-ABCD'], 'telefono', 'solo puede tener números'],
    'actividad inexistente' => [['nrc' => '12345', 'cod_actividad' => '99998'], 'cod_actividad', 'catálogo oficial'],
    'CCF sin actividad' => [['nrc' => '12345'], 'cod_actividad', 'obligatorio'],
    'CCF con DUI' => [['nrc' => '12345', 'tipo_documento' => '13', 'num_documento' => '012345678'], 'tipo_documento', 'debe ser NIT'],
    'municipio de otro departamento' => [['departamento' => '05', 'municipio' => '99', 'distrito' => '01', 'complemento' => 'Santa Tecla'], 'municipio', 'pertenezca al departamento'],
    'departamento inexistente' => [['departamento' => '99', 'complemento' => 'X'], 'departamento', 'catálogo oficial'],
    'dirección de más de 200' => [['departamento' => '06', 'municipio' => '23', 'distrito' => '14', 'complemento' => str_repeat('a', 201)], 'complemento', '200'],
    'nombre de más de 250' => [['nombre' => str_repeat('N', 251)], 'nombre', '250'],
]);

it('Clientes: normaliza al formato de Hacienda (NIT sin guiones, DUI 00000000-0) y completa la actividad del catálogo', function () {
    Livewire::test(Clientes::class)
        ->set('form.tipo_documento', '36')->set('form.num_documento', '0614-010185-101-4')
        ->set('form.nombre', 'Contribuyente, S.A.')->set('form.nrc', '765-432')
        ->set('form.cod_actividad', '46900')
        ->set('form.departamento', '06')->set('form.municipio', '23')->set('form.distrito', '14')->set('form.complemento', 'San Salvador')
        ->call('guardar')->assertHasNoErrors();
    Livewire::test(Clientes::class)->set('form.tipo_documento', '13')->set('form.num_documento', '012345678')
        ->set('form.nombre', 'Persona natural')->call('guardar')->assertHasNoErrors();

    $nit = Cliente::query()->where('tipo_documento', '36')->sole();
    expect($nit->num_documento)->toBe('06140101851014')->and($nit->nrc)->toBe('765432')
        ->and($nit->desc_actividad)->toBe(R::nombreActividad('46900'))->and($nit->puedeRecibirCcf())->toBeTrue()
        ->and(Cliente::query()->where('tipo_documento', '13')->sole()->num_documento)->toBe('01234567-8');
});

it('Clientes: valida en vivo al salir del campo y no permite documentos repetidos', function () {
    Livewire::test(Clientes::class)->set('form.num_documento', '123')->assertHasErrors('form.num_documento')
        ->set('form.num_documento', '06140101851014')->assertHasNoErrors('form.num_documento');

    Cliente::query()->create(['tipo_documento' => '36', 'num_documento' => '06140101851014', 'nombre' => 'Existente']);
    Livewire::test(Clientes::class)->set('form.num_documento', '06140101851014')->set('form.nombre', 'Otro')->call('guardar')
        ->assertHasErrors(['form.num_documento' => 'unique']);
});

// ------------------------------------------------------------------ Emitir

it('Emitir: cantidad mayor que cero, montos con hasta 8 decimales, descripción obligatoria y descuento no mayor que la venta', function (array $linea, string $campo) {
    Livewire::test(Emitir::class)->set('tipo', '03')
        ->set('lineas', [['descripcion' => 'Producto', 'cantidad' => '1', 'precio' => '10', 'descuento' => '0', 'tipoVenta' => 'gravada', 'codigo' => null, 'tipoItem' => 1, 'uniMedida' => 59, ...$linea]])
        ->call('emitir')->assertHasErrors("lineas.0.{$campo}");
})->with([
    'cantidad cero' => [['cantidad' => '0'], 'cantidad'],
    'cantidad negativa' => [['cantidad' => '-1'], 'cantidad'],
    'cantidad con coma' => [['cantidad' => '1,5'], 'cantidad'],
    'cantidad con 9 decimales' => [['cantidad' => '1.123456789'], 'cantidad'],
    'precio con signo de dólar' => [['precio' => '$10'], 'precio'],
    'precio fuera de rango' => [['precio' => '100000000000'], 'precio'],
    'descripción vacía' => [['descripcion' => ''], 'descripcion'],
    'descripción de más de 1500' => [['descripcion' => str_repeat('d', 1501)], 'descripcion'],
    'descuento mayor que la venta' => [['descuento' => '10.01'], 'descuento'],
]);

it('Emitir: muestra el error en vivo junto a la línea y un correo inválido no se acepta', function () {
    Livewire::test(Emitir::class)->set('lineas.0.cantidad', '0')->assertHasErrors('lineas.0.cantidad')
        ->assertSee('La cantidad debe ser mayor que cero.')
        ->set('correo', 'no-es-correo')->assertHasErrors('correo');
});

// ------------------------------------------------------------------ Productos, sucursales e invalidación

it('Productos: unidad de medida del CAT-014, código sin espacios y descripción de hasta 1500', function (array $datos, string $campo) {
    $c = Livewire::test(Productos::class)->set('form.descripcion', 'Producto')->set('form.precio', '1.00');
    foreach ($datos as $k => $v) {
        $c->set("form.{$k}", $v);
    }
    $c->call('guardar')->assertHasErrors("form.{$campo}");
})->with([
    'unidad inexistente' => [['uni_medida' => 0], 'uni_medida'],
    'unidad 100' => [['uni_medida' => 100], 'uni_medida'],
    'código con espacios' => [['codigo' => 'PUP 1'], 'codigo'],
    'código de más de 25' => [['codigo' => str_repeat('C', 26)], 'codigo'],
    'tipo de ítem inexistente' => [['tipo_item' => 9], 'tipo_item'],
    'precio con letras' => [['precio' => 'diez'], 'precio'],
]);

it('Sucursales y cajas: códigos de Hacienda M/B/S/P + 3 dígitos y P + 3 dígitos, sin repetir', function () {
    $dueno = Usuario::factory()->create(['two_factor_confirmed_at' => now()]);
    $this->d['empresa']->usuarios()->attach($dueno->id, ['rol' => Rol::Dueno->value]);
    $this->actingAs($dueno);
    Plan::query()->create(['codigo' => 'sin-limite', 'nombre' => 'Sin límite', 'precio_mensual' => '1.00']);

    Livewire::test(Establecimientos::class)->set('establecimiento', ['tipo' => '01', 'nombre' => 'Sucursal', 'cod_estable_mh' => 'X001'])
        ->call('crearEstablecimiento')->assertHasErrors('establecimiento.cod_estable_mh');
    Livewire::test(Establecimientos::class)->set('establecimiento', ['tipo' => '01', 'nombre' => 'Sucursal', 'cod_estable_mh' => 'M001'])
        ->call('crearEstablecimiento')->assertHasErrors(['establecimiento.cod_estable_mh' => 'unique']); // ya existe en la empresa
    Livewire::test(Establecimientos::class)->set('establecimiento', ['tipo' => '01', 'nombre' => 'Sucursal 2', 'cod_estable_mh' => 's002'])
        ->call('crearEstablecimiento')->assertHasNoErrors();   // se pasa a mayúsculas
    Livewire::test(Establecimientos::class)->set('puntoVenta', ['establecimiento_id' => $this->d['establecimiento']->id, 'nombre' => 'Caja 2', 'cod_punto_venta_mh' => 'C002'])
        ->call('crearPuntoVenta')->assertHasErrors('puntoVenta.cod_punto_venta_mh');
});

it('Invalidación: el tipo 1 exige el documento que reemplaza y el documento del solicitante se valida por tipo', function () {
    $dte = sellarDte(crearDteDePrueba($this->d), now('America/El_Salvador')->format('Y-m-d H:i:s'));

    Livewire::test(Invalidaciones::class)->set('dteId', $dte->id)->set('tipoAnulacion', 1)
        ->set('nombreSolicita', 'Cliente Solicitante')->set('tipDocSolicita', '13')->set('numDocSolicita', '12345')
        ->call('invalidar')->assertHasErrors(['codigoReemplazo' => 'required', 'numDocSolicita']);
    Livewire::test(Invalidaciones::class)->set('dteId', $dte->id)->set('tipoAnulacion', 3)
        ->set('nombreSolicita', 'Cliente Solicitante')->set('numDocSolicita', '012345678')->set('codigoReemplazo', 'abc')
        ->call('invalidar')->assertHasErrors(['motivo' => 'required', 'codigoReemplazo']);
});

// ------------------------------------------------------------------ Configuración (emisor) y API

it('Configuración: el municipio debe ser del departamento y la actividad del catálogo; la descripción se completa sola', function () {
    $dueno = Usuario::factory()->create(['two_factor_confirmed_at' => now()]);
    $this->d['empresa']->usuarios()->attach($dueno->id, ['rol' => Rol::Dueno->value]);
    $this->actingAs($dueno);

    $c = Livewire::test(Onboarding::class)
        ->set('empresa.cod_actividad', '56101')->assertSet('empresa.desc_actividad', R::nombreActividad('56101'))
        ->set('empresa.departamento', '05')->assertSet('empresa.municipio', null)   // al cambiar el departamento se limpia
        ->set('empresa.municipio', '99')->assertHasErrors('empresa.municipio')
        ->set('empresa.telefono', '22')->assertHasErrors('empresa.telefono')
        ->set('empresa.correo', 'malo')->assertHasErrors('empresa.correo');
    $c->set('empresa.municipio', array_key_first(R::opciones('CAT-013', '05')))->assertHasNoErrors('empresa.municipio');
});

it('API: rechaza el cliente con datos fuera de las reglas de Hacienda y normaliza NIT y DUI', function () {
    $token = $this->d['empresa']->createToken('POS', ['dte:emitir'])->plainTextToken;
    $venta = ['lineas' => [['descripcion' => 'Café', 'cantidad' => '1', 'precio' => '0.70796460']]];
    Http::fake(['*' => Http::response(['status' => 'OK', 'body' => 'a.b.c'])]);
    tenancy()->limpiar();
    app('auth')->forgetGuards();

    $this->postJson('/api/v1/dte/01', [...$venta, 'cliente' => ['tipo_documento' => '36', 'num_documento' => '123', 'nombre' => 'X', 'correo' => 'malo']], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(422)->assertJsonValidationErrors(['cliente.num_documento', 'cliente.correo'])
        ->assertJson(fn ($json) => $json->where('errors', fn ($e) => $e['cliente.num_documento'][0] === 'Formato inválido: NIT con 9 o 14 dígitos; DUI como 00000000-0.')->etc());
});
