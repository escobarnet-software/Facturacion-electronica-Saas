<?php

use App\Dte\Contribuyente\CreditoFiscalBuilder;
use App\Dte\Contribuyente\DocumentoContribuyente;
use App\Dte\Contribuyente\DocumentoRelacionado;
use App\Dte\Contribuyente\Linea;
use App\Dte\Contribuyente\NotaCreditoBuilder;
use App\Dte\Contribuyente\NotaDebitoBuilder;
use App\Dte\Contribuyente\NotaRemisionBuilder;
use App\Dte\Contribuyente\ReceptorContribuyente;
use App\Dte\Correlativos\Correlativos;
use App\Dte\Especiales\ContableLiquidacionBuilder;
use App\Dte\Especiales\DonacionBuilder;
use App\Dte\Especiales\ExportacionBuilder;
use App\Dte\Especiales\LiquidacionBuilder;
use App\Dte\Especiales\RetencionBuilder;
use App\Dte\Especiales\SujetoExcluidoBuilder;
use App\Dte\Factura\FacturaBuilder;
use App\Dte\Modelos\Emisor;
use App\Dte\Modelos\LineaVenta;
use App\Dte\Modelos\Receptor;
use App\Dte\Modelos\Venta;
use App\Enums\EstadoDte;
use App\Enums\Rol;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\EmpresaCredencial;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Models\Usuario;
use App\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
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
    $fecha = CarbonImmutable::now('America/El_Salvador')->toDateTimeImmutable();
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

/**
 * Crea un DTE de prueba de cualquier tipo independiente (03, 04, 07, 08, 09, 11, 14, 15)
 * con los builders reales, para la prueba contra Hacienda.
 *
 * @param  array{empresa: Empresa, establecimiento: Establecimiento, puntoVenta: PuntoVenta}  $d
 */
function crearDteDeTipo(array $d, string $tipo): Dte
{
    $e = $d['empresa'];
    $emisor = new Emisor(
        nit: $e->nit, nrc: (string) $e->nrc, nombre: $e->razon_social, codActividad: '46900',
        descActividad: 'Venta al por mayor de otros productos', nombreComercial: $e->nombre_comercial,
        departamento: '06', municipio: '23', distrito: '14', complemento: 'San Salvador',
        telefono: '22223333', correo: 'dte@empresa.test', codEstable: 'M001', codPuntoVenta: 'P001',
    );
    $fecha = CarbonImmutable::now('America/El_Salvador')->toDateTimeImmutable();
    $n = app(Correlativos::class)->siguiente($e, $tipo, $d['establecimiento'], $d['puntoVenta'], $fecha);
    $cliente = new ReceptorContribuyente('06140101851014', '765432', 'CLIENTE DE PRUEBA, S.A. DE C.V.', '46900', 'Venta al por mayor', '06', '23', '14', 'San Salvador', 'cliente@prueba.test');
    $doc = fn (array $extra = []) => new DocumentoContribuyente([Linea::gravada('Mercadería de prueba', '1', '100.00')], $cliente, $n, $fecha, ...$extra);
    $especial = fn (string $clase) => new $clase;

    $relacionado = new DocumentoRelacionado('03', 'AC54E9DB-79C5-42CE-B430-EC552C97EFBA', $fecha);
    $ajuste = fn () => new DocumentoContribuyente([Linea::gravada('Ajuste de prueba', '1', '10.00', '0', null, 'AC54E9DB-79C5-42CE-B430-EC552C97EFBA')], $cliente, $n, $fecha, relacionados: [$relacionado]);

    if ($tipo === '01') {
        return crearDteDePrueba($d);
    }

    $armado = match ($tipo) {
        '05' => (new NotaCreditoBuilder($emisor))->construir($ajuste()),
        '06' => (new NotaDebitoBuilder($emisor))->construir($ajuste()),
        '03' => (new CreditoFiscalBuilder($emisor))->construir($doc()),
        '04' => (new NotaRemisionBuilder($emisor))->construir($doc(['bienTitulo' => '04'])),
        '07' => $especial(RetencionBuilder::class)->construir($emisor, $cliente, [['tipoDte' => '03', 'tipoGeneracion' => 1, 'numeroDocumento' => '123456', 'fechaEmision' => $fecha->format('Y-m-d'), 'montoSujetoGrav' => '100.00', 'codigoRetencionMH' => '22']], $n, $fecha),
        '08' => $especial(LiquidacionBuilder::class)->construir($emisor, $cliente, 1, [['tipoDte' => '03', 'tipoGeneracion' => 1, 'numeroDocumento' => '123456', 'fechaEmision' => $fecha->format('Y-m-d'), 'ventaGravada' => '100.00']], $n, $fecha),
        '09' => $especial(ContableLiquidacionBuilder::class)->construir($emisor, ['nit' => '06140101851014', 'nombre' => 'AFILIADO DE PRUEBA', 'codActividad' => '56101', 'descActividad' => 'Restaurantes', 'tipoEstablecimiento' => '02', 'correo' => 'afiliado@prueba.test'], ['inicio' => $fecha->format('Y-m-01'), 'fin' => $fecha->format('Y-m-d'), 'valorOperaciones' => '113.00', 'montoSujetoPercepcion' => '100.00', 'iva' => '13.00'], ['nombEntrega' => 'Persona de prueba', 'docuEntrega' => '012345678'], $n, $fecha),
        '11' => $especial(ExportacionBuilder::class)->construir($emisor, ['tipoItemExpor' => 2], ['nombre' => 'CLIENTE EXTERIOR', 'tipoDocumento' => '37', 'numDocumento' => 'EXT-1', 'descActividad' => 'Servicios informáticos', 'codPais' => 'US', 'nombrePais' => 'Estados Unidos', 'complemento' => 'Miami', 'tipoPersona' => 2], [['descripcion' => 'Servicio de prueba', 'cantidad' => '1', 'precio' => '100.00', 'tipoItem' => 2]], $n, $fecha),
        '14' => $especial(SujetoExcluidoBuilder::class)->construir($emisor, ['tipoDocumento' => '13', 'numDocumento' => '012345678', 'nombre' => 'Persona de prueba', 'departamento' => '06', 'municipio' => '23', 'distrito' => '14', 'complemento' => 'San Salvador'], [['descripcion' => 'Compra de prueba', 'cantidad' => '1', 'precio' => '50.00']], $n, $fecha),
        '15' => $especial(DonacionBuilder::class)->construir($emisor, '36', ['tipoDocumento' => '36', 'numDocumento' => '06140101851014', 'nombre' => 'DONANTE DE PRUEBA', 'codDomiciliado' => 1, 'codPais' => '9300'], [['tipoDonacion' => 1, 'descripcion' => 'Donación de prueba', 'cantidad' => '1', 'valorUni' => '50.00']], [['codDocAsociado' => 1, 'descDocumento' => 'Acuerdo', 'detalleDocumento' => 'Acuerdo de prueba']], $n, $fecha),
    };

    return Dte::query()->create([
        'establecimiento_id' => $d['establecimiento']->id, 'punto_venta_id' => $d['puntoVenta']->id,
        'tipo' => $tipo, 'version' => $armado->version, 'ambiente' => '00',
        'codigo_generacion' => $armado->codigoGeneracion, 'numero_control' => $armado->numeroControl,
        'fecha_emision' => $fecha->format('Y-m-d'), 'hora_emision' => $fecha->format('H:i:s'), 'estado' => 'borrador',
        'json_texto' => $armado->json, 'json_original' => $armado->documento(),
    ]);
}

/** Deja un DTE de prueba como transmitido, con sello y fecha de procesamiento (hora de El Salvador). */
function sellarDte(Dte $dte, string $fechaSello): Dte
{
    $dte->forceFill([
        'estado' => EstadoDte::Transmitido,
        'sello_recibido' => strtoupper(substr(hash('sha256', $dte->codigo_generacion), 0, 40)),
        'fh_procesamiento' => CarbonImmutable::parse($fechaSello, 'America/El_Salvador'),
        'jws' => $dte->jws ?? 'eyJhbGciOiJSUzUxMiJ9.FALSO.FIRMA',
    ])->save();

    return $dte->refresh();
}

/** Dte sin guardar, solo para calcular plazos. */
function dteParaPlazos(string $tipo, string $fechaSello, bool $medicamento = false): Dte
{
    return (new Dte)->forceFill([
        'tipo' => $tipo, 'sello_recibido' => str_repeat('A', 40), 'medicamento_perecedero' => $medicamento,
        'fh_procesamiento' => CarbonImmutable::parse($fechaSello, 'America/El_Salvador'),
    ]);
}
