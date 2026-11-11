<?php

use App\Dte\Correlativos\Correlativos;
use App\Dte\Support\CodigoGeneracion;
use App\Enums\EstadoDte;
use App\Enums\Rol;
use App\Jobs\Middleware\ConEmpresa;
use App\Models\Bitacora;
use App\Models\Cliente;
use App\Models\Contingencia;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\DteEventoInterno;
use App\Models\Empresa;
use App\Models\EmpresaCredencial;
use App\Models\EmpresaUsuario;
use App\Models\Establecimiento;
use App\Models\Evento;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\PuntoVenta;
use Illuminate\Support\Facades\DB;

/*
| Terminado cuando (Paso 1): la empresa A no puede leer ni escribir datos de la
| empresa B, ni por Eloquent (EmpresaScope) ni por SQL directo (RLS).
*/

const TABLAS_DE_NEGOCIO = ['establecimientos', 'puntos_venta', 'empresa_usuario', 'empresa_credenciales', 'bitacora', 'correlativos', 'dtes', 'dte_eventos_internos', 'eventos', 'contingencias', 'lotes', 'productos', 'clientes'];

beforeEach(function () {
    $this->a = crearEmpresaDePrueba();
    $this->b = crearEmpresaDePrueba();

    foreach ([$this->a, $this->b] as $datos) {
        tenancy()->ejecutarComo($datos['empresa']->id, function () use ($datos) {
            Bitacora::registrar('prueba.creada');
            app(Correlativos::class)->siguiente($datos['empresa'], '01', $datos['establecimiento'], $datos['puntoVenta'], now());
            crearDteDePrueba($datos)->transicionar(EstadoDte::Validado, 'prueba');
            $c = Contingencia::query()->create(['ambiente' => '00', 'inicio' => now(), 'tipo' => 1]);
            Evento::query()->create(['tipo' => 'contingencia', 'version' => 4, 'ambiente' => '00', 'codigo_generacion' => CodigoGeneracion::nuevo(), 'estado' => 'borrador', 'json_texto' => '{}', 'json_original' => []]);
            Lote::query()->create(['contingencia_id' => $c->id, 'id_envio' => CodigoGeneracion::nuevo(), 'estado' => 'recibido', 'dte_ids' => []]);
            Producto::query()->create(['descripcion' => 'Producto', 'precio' => '1']);
            Cliente::query()->create(['tipo_documento' => '13', 'num_documento' => '012345678', 'nombre' => 'Cliente']);
        });
    }
});

it('se conecta con un rol sin superusuario ni BYPASSRLS (si no, RLS no aplicaría)', function () {
    $rol = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');

    expect($rol->rolsuper)->toBeFalse()->and($rol->rolbypassrls)->toBeFalse();
});

it('tiene RLS activado y forzado en todas las tablas de negocio', function () {
    foreach ([...TABLAS_DE_NEGOCIO, 'empresas'] as $tabla) {
        $fila = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?', [$tabla]);
        expect($fila->relrowsecurity)->toBeTrue("RLS no activo en {$tabla}")
            ->and($fila->relforcerowsecurity)->toBeTrue("RLS no forzado en {$tabla}");
    }
});

it('por Eloquent, la empresa A solo ve sus propios datos', function () {
    tenancy()->establecer($this->a['empresa']->id);
    $idA = $this->a['empresa']->id;

    foreach ([Establecimiento::class, PuntoVenta::class, EmpresaCredencial::class, EmpresaUsuario::class, Bitacora::class, Correlativo::class, Dte::class, DteEventoInterno::class, Evento::class, Contingencia::class, Lote::class, Producto::class, Cliente::class] as $modelo) {
        $empresas = $modelo::query()->pluck('empresa_id')->unique()->values()->all();
        expect($empresas)->toBe([$idA], "{$modelo} filtró datos de otra empresa");
    }

    expect(Establecimiento::query()->find($this->b['establecimiento']->id))->toBeNull()
        ->and(EmpresaCredencial::query()->find($this->b['credencial']->id))->toBeNull()
        ->and(Empresa::query()->pluck('id')->all())->toBe([$idA]);
});

it('por SQL directo (sin EmpresaScope), RLS solo devuelve filas de la empresa A', function () {
    tenancy()->establecer($this->a['empresa']->id);

    foreach (TABLAS_DE_NEGOCIO as $tabla) {
        $empresas = collect(DB::select("SELECT DISTINCT empresa_id FROM {$tabla}"))->pluck('empresa_id')->all();
        expect($empresas)->toBe([$this->a['empresa']->id], "RLS filtró datos de otra empresa en {$tabla}");
    }

    expect(collect(DB::select('SELECT id FROM empresas'))->pluck('id')->all())->toBe([$this->a['empresa']->id]);
});

it('sin empresa activa no se ve nada, ni por Eloquent ni por SQL (falla cerrado)', function () {
    tenancy()->limpiar();

    expect(Establecimiento::query()->count())->toBe(0)
        ->and(EmpresaCredencial::query()->count())->toBe(0);

    foreach ([...TABLAS_DE_NEGOCIO, 'empresas'] as $tabla) {
        expect(DB::selectOne("SELECT count(*) AS n FROM {$tabla}")->n)->toBe(0, "Se ven filas de {$tabla} sin empresa");
    }
});

it('por SQL directo, la empresa A no puede insertar, modificar ni borrar datos de B', function () {
    tenancy()->establecer($this->a['empresa']->id);
    $b = $this->b;
    $idB = $b['empresa']->id;

    fallaEnBaseDeDatos(fn () => DB::insert(
        "INSERT INTO establecimientos (empresa_id, tipo, nombre, cod_estable_mh, created_at, updated_at) VALUES (?, '01', 'Intruso', 'S999', now(), now())",
        [$idB],
    ));
    fallaEnBaseDeDatos(fn () => DB::insert(
        "INSERT INTO bitacora (empresa_id, accion) VALUES (?, 'intruso')", [$idB],
    ));
    fallaEnBaseDeDatos(fn () => DB::insert(
        "INSERT INTO empresa_credenciales (empresa_id, ambiente, usuario_api, pwd_api, pwd_privada) VALUES (?, '01', 'x', 'x', 'x')", [$idB],
    ));

    expect(DB::update("UPDATE dte_eventos_internos SET motivo = 'alterado' WHERE empresa_id = ?", [$idB]))->toBe(0)
        ->and(DB::delete('DELETE FROM dte_eventos_internos WHERE empresa_id = ?', [$idB]))->toBe(0);

    foreach (['establecimientos', 'puntos_venta', 'empresa_usuario', 'empresa_credenciales', 'correlativos', 'dtes', 'eventos', 'contingencias', 'lotes', 'productos', 'clientes'] as $tabla) {
        expect(DB::update("UPDATE {$tabla} SET updated_at = now() WHERE empresa_id = ?", [$idB]))->toBe(0, "Se modificó {$tabla} de B")
            ->and(DB::delete("DELETE FROM {$tabla} WHERE empresa_id = ?", [$idB]))->toBe(0, "Se borró {$tabla} de B");
    }
    expect(DB::update("UPDATE empresas SET razon_social = 'X' WHERE id = ?", [$idB]))->toBe(0);

    // Mover una fila propia hacia B tampoco se permite.
    fallaEnBaseDeDatos(fn () => DB::update('UPDATE establecimientos SET empresa_id = ? WHERE id = ?', [$idB, $this->a['establecimiento']->id]));

    // Comprobación desde B: sus datos siguen intactos.
    tenancy()->establecer($idB);
    expect(Establecimiento::query()->count())->toBe(1)
        ->and(EmpresaCredencial::query()->first()?->pwd_api)->toBe('ClaveApiFalsa#2026');
});

it('por Eloquent, la empresa A no puede crear datos para B', function () {
    tenancy()->establecer($this->a['empresa']->id);

    expect(fn () => Establecimiento::query()->create([
        'empresa_id' => $this->b['empresa']->id, 'tipo' => '01', 'nombre' => 'Intruso', 'cod_estable_mh' => 'S999',
    ]))->toThrow(LogicException::class);

    $nuevo = Establecimiento::query()->create(['tipo' => '01', 'nombre' => 'Sucursal', 'cod_estable_mh' => 'S001']);
    expect($nuevo->empresa_id)->toBe($this->a['empresa']->id);
});

it('sin empresa activa no se pueden crear datos de negocio', function () {
    tenancy()->limpiar();

    expect(fn () => Establecimiento::query()->create(['tipo' => '01', 'nombre' => 'X', 'cod_estable_mh' => 'S001']))
        ->toThrow(LogicException::class);
});

it('un usuario ve las empresas a las que pertenece, y ninguna otra', function () {
    $contador = $this->a['usuario'];
    $c = crearEmpresaDePrueba(Rol::Contador, $contador);

    tenancy()->limpiar();
    tenancy()->fijarUsuario($contador->id);

    expect(collect(DB::select('SELECT id FROM empresas ORDER BY id'))->pluck('id')->all())
        ->toBe([$this->a['empresa']->id, $c['empresa']->id]);
});

it('ejecutarComo restaura la empresa anterior', function () {
    tenancy()->establecer($this->a['empresa']->id);

    $visto = tenancy()->ejecutarComo($this->b['empresa']->id, fn () => Establecimiento::query()->value('empresa_id'));

    expect($visto)->toBe($this->b['empresa']->id)
        ->and(tenancy()->empresaId())->toBe($this->a['empresa']->id)
        ->and(Establecimiento::query()->value('empresa_id'))->toBe($this->a['empresa']->id);
});

it('los jobs fijan la empresa recibida y la limpian al terminar', function () {
    tenancy()->limpiar();
    $idB = $this->b['empresa']->id;

    $visto = (new ConEmpresa($idB))->handle(new stdClass, fn () => [
        tenancy()->empresaId(),
        DB::selectOne("SELECT current_setting('app.empresa_id', true) AS e")->e,
        Establecimiento::query()->pluck('empresa_id')->unique()->all(),
    ]);

    expect($visto)->toBe([$idB, (string) $idB, [$idB]])
        ->and(tenancy()->empresaId())->toBeNull()
        ->and(DB::selectOne("SELECT current_setting('app.empresa_id', true) AS e")->e)->toBe('');
});
