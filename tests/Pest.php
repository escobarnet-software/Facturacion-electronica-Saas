<?php

use App\Enums\Rol;
use App\Models\Empresa;
use App\Models\EmpresaCredencial;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Models\Usuario;
use App\Tenancy\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pest
|--------------------------------------------------------------------------
| Las pruebas de Feature usan la aplicación Laravel completa con PostgreSQL
| (nunca SQLite: hacen falta RLS y NUMERIC).
*/

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

// Concurrencia: procesos reales con conexiones propias, sin la transacción de RefreshDatabase.
pest()->extend(TestCase::class)->in('Concurrencia');

function tenancy(): Tenancy
{
    return app(Tenancy::class);
}

/**
 * Crea una empresa completa de prueba: establecimiento, punto de venta,
 * credenciales (con secretos FALSOS) y un usuario con el rol indicado.
 *
 * @return array{empresa: Empresa, establecimiento: Establecimiento, puntoVenta: PuntoVenta, credencial: EmpresaCredencial, usuario: Usuario}
 */
function crearEmpresaDePrueba(Rol $rol = Rol::Contador, ?Usuario $usuario = null): array
{
    // Crear empresas es una operación de plataforma (fuera de cualquier empresa).
    return tenancy()->sinAislamiento(function () use ($rol, $usuario) {
        $empresa = Empresa::factory()->create();

        return tenancy()->ejecutarComo($empresa->id, function () use ($empresa, $rol, $usuario) {
            $establecimiento = Establecimiento::factory()->create();
            $puntoVenta = PuntoVenta::query()->create([
                'establecimiento_id' => $establecimiento->id,
                'nombre' => 'Caja 1',
                'cod_punto_venta_mh' => 'P001',
            ]);
            $credencial = (new EmpresaCredencial(['ambiente' => '00', 'usuario_api' => $empresa->nit]))
                ->establecerSecretos('ClaveApiFalsa#2026', 'LlavePrivadaFalsa#2026');
            $credencial->save();

            $usuario ??= Usuario::factory()->create();
            $empresa->usuarios()->attach($usuario->id, ['rol' => $rol->value]);

            return compact('empresa', 'establecimiento', 'puntoVenta', 'credencial', 'usuario');
        });
    });
}

/** Ejecuta SQL que DEBE fallar sin abortar la transacción de la prueba. */
function fallaEnBaseDeDatos(Closure $sql): void
{
    expect(fn () => DB::transaction($sql))->toThrow(QueryException::class);
}
