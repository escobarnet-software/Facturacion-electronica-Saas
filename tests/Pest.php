<?php

use App\Dte\Correlativos\Correlativos;
use App\Dte\Factura\FacturaBuilder;
use App\Dte\Modelos\Emisor;
use App\Dte\Modelos\LineaVenta;
use App\Dte\Modelos\Receptor;
use App\Dte\Modelos\Venta;
use App\Enums\Rol;
use App\Models\Dte;
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

/**
 * Crea un DTE de prueba (Factura 01 de la pupusería) para la empresa activa,
 * armado con el FacturaBuilder real y validado contra el esquema oficial.
 *
 * @param  array{empresa: Empresa, establecimiento: Establecimiento, puntoVenta: PuntoVenta}  $d
 */
function crearDteDePrueba(array $d, string $estado = 'borrador'): Dte
{
    $emisor = new Emisor(
        nit: $d['empresa']->nit, nrc: '1234567', nombre: 'PUPUSERÍA DE PRUEBA, S.A. DE C.V.',
        codActividad: '56101', descActividad: 'Restaurantes', nombreComercial: 'Pupusería de prueba',
        departamento: '06', municipio: '23', distrito: '14', complemento: 'Colonia Escalón, San Salvador',
        telefono: '22223333', correo: 'ventas@pupuseria.test', codEstable: 'M001', codPuntoVenta: 'P001',
    );
    $fecha = new DateTimeImmutable('now', new DateTimeZone('America/El_Salvador'));
    $correlativo = app(Correlativos::class)
        ->siguiente($d['empresa'], '01', $d['establecimiento'], $d['puntoVenta'], $fecha);

    $factura = (new FacturaBuilder($emisor, '00'))->construir(new Venta(
        lineas: [new LineaVenta('Pupusa revuelta', '6', '0.75'), new LineaVenta('Horchata', '2', '1.00')],
        receptor: Receptor::consumidorFinal(),
        correlativo: $correlativo,
        fechaHora: $fecha,
    ));

    return Dte::query()->create([
        'establecimiento_id' => $d['establecimiento']->id,
        'punto_venta_id' => $d['puntoVenta']->id,
        'tipo' => '01', 'version' => 2, 'ambiente' => '00',
        'codigo_generacion' => $factura->codigoGeneracion,
        'numero_control' => $factura->numeroControl,
        'fecha_emision' => $fecha->format('Y-m-d'), 'hora_emision' => $fecha->format('H:i:s'),
        'estado' => $estado,
        'total_gravada' => (string) $factura->totalPagar, 'total_iva' => (string) $factura->totalIva, 'total_pagar' => (string) $factura->totalPagar,
        'json_texto' => $factura->json,
        'json_original' => $factura->documento(),
        'jws' => $estado === 'firmado' ? 'eyJhbGciOiJSUzUxMiJ9.FALSO.FIRMA' : null,
    ]);
}
