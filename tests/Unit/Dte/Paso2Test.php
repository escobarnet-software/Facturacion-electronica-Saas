<?php

declare(strict_types=1);

namespace Tests\Unit\Dte;

use App\Dte\Factura\FacturaBuilder;
use App\Dte\Modelos\Emisor;
use App\Dte\Modelos\LineaVenta;
use App\Dte\Modelos\Receptor;
use App\Dte\Modelos\Venta;
use App\Dte\Support\CodigoGeneracion;
use App\Dte\Support\NumeroALetras;
use App\Dte\Validacion\RegistroEsquemas;
use App\Dte\Validacion\ValidadorEsquema;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Terminado cuando (Paso 2): UUID, NumeroALetras (0.01, 1, 21, 100,
 * 1,000,000.99) y validación negativa de esquema.
 */
final class Paso2Test extends TestCase
{
    private const ESQUEMAS = __DIR__.'/../../../resources/schemas';

    private function facturaJson(): string
    {
        $emisor = new Emisor(
            nit: '06142803901121',
            nrc: '1234567',
            nombre: 'PUPUSERÍA LA BENDICIÓN, S.A. DE C.V.',
            codActividad: '56101',
            descActividad: 'Restaurantes',
            nombreComercial: 'Pupusería La Bendición',
            departamento: '06',
            municipio: '23',
            distrito: '01',
            complemento: 'Colonia Escalón, San Salvador',
            telefono: '22223333',
            correo: 'ventas@pupuseria.test',
            codEstable: 'M001',
            codPuntoVenta: 'P001',
        );

        $venta = new Venta(
            lineas: [new LineaVenta('Pupusa revuelta', '6', '0.75'), new LineaVenta('Horchata', '2', '1.00')],
            receptor: Receptor::consumidorFinal(),
            correlativo: 1,
            fechaHora: new DateTimeImmutable('2026-11-11 10:30:00', new DateTimeZone('America/El_Salvador')),
        );

        return (new FacturaBuilder($emisor, '00'))->construir($venta)->json;
    }

    public function test_codigo_generacion_uuid_v4_mayusculas_36_caracteres_y_unico(): void
    {
        $codigos = [];
        for ($i = 0; $i < 500; $i++) {
            $c = CodigoGeneracion::nuevo();
            $this->assertSame(36, strlen($c));
            $this->assertSame(strtoupper($c), $c);
            $this->assertMatchesRegularExpression('/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/', $c);
            $codigos[] = $c;
        }
        $this->assertCount(500, array_unique($codigos));
        $this->assertFalse(CodigoGeneracion::esValido(strtolower($codigos[0])));
    }

    /** @return array<string, array{string, string}> */
    public static function montosEnLetras(): array
    {
        return [
            '0.01' => ['0.01', 'CERO CON 01/100 DÓLARES'],
            '1' => ['1', 'UNO CON 00/100 DÓLARES'],
            '21' => ['21', 'VEINTIUNO CON 00/100 DÓLARES'],
            '100' => ['100', 'CIEN CON 00/100 DÓLARES'],
            '1,000,000.99' => ['1000000.99', 'UN MILLÓN CON 99/100 DÓLARES'],
        ];
    }

    #[DataProvider('montosEnLetras')]
    public function test_numero_a_letras(string $monto, string $esperado): void
    {
        $this->assertSame($esperado, NumeroALetras::convertir($monto));
    }

    public function test_la_factura_construida_cumple_el_esquema_oficial(): void
    {
        $validador = new ValidadorEsquema(self::ESQUEMAS);

        $this->assertSame([], $validador->validar($this->facturaJson(), '01'));
    }

    public function test_validacion_negativa_rechaza_un_documento_que_no_cumple_el_esquema(): void
    {
        $validador = new ValidadorEsquema(self::ESQUEMAS);
        $dte = json_decode($this->facturaJson(), true, flags: JSON_THROW_ON_ERROR);

        $casos = [
            'tipoDte de otro documento' => fn (array $d) => array_replace_recursive($d, ['identificacion' => ['tipoDte' => '03']]),
            'código en minúsculas' => fn (array $d) => array_replace_recursive($d, ['identificacion' => ['codigoGeneracion' => strtolower($d['identificacion']['codigoGeneracion'])]]),
            'número de control corto' => fn (array $d) => array_replace_recursive($d, ['identificacion' => ['numeroControl' => 'DTE-01-M001P001-1']]),
            'campo desconocido' => fn (array $d) => $d + ['campoInventado' => 1],
            'falta el resumen' => function (array $d) {
                unset($d['resumen']);

                return $d;
            },
            'ambiente inválido' => fn (array $d) => array_replace_recursive($d, ['identificacion' => ['ambiente' => '02']]),
        ];

        foreach ($casos as $nombre => $alterar) {
            $json = json_encode($alterar($dte), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $this->assertNotSame([], $validador->validar($json, '01', 2), "Debió rechazar: {$nombre}");
        }
    }

    public function test_el_registro_conoce_todos_los_tipos_y_eventos_oficiales(): void
    {
        $registro = new RegistroEsquemas(self::ESQUEMAS);

        foreach ([...array_keys(RegistroEsquemas::DTE), ...array_keys(RegistroEsquemas::EVENTOS)] as $clave) {
            $this->assertFileExists($registro->ruta((string) $clave));
        }

        $this->assertSame(2, $registro->versionVigente('01'));
        $this->assertSame(4, $registro->versionVigente('03'));
        $this->assertSame(3, $registro->versionVigente('11'));
        $this->assertSame(3, $registro->versionVigente('invalidacion'));

        $this->expectException(RuntimeException::class);
        $registro->ruta('01', 1);
    }

    public function test_cada_esquema_declara_el_tipo_y_la_version_de_su_registro(): void
    {
        $registro = new RegistroEsquemas(self::ESQUEMAS);

        foreach (RegistroEsquemas::DTE as $tipo => $versiones) {
            $tipo = (string) $tipo; // PHP convierte las claves '11', '14', '15' en enteros
            foreach ($versiones as $version => $archivo) {
                $esquema = json_decode((string) file_get_contents($registro->ruta($tipo, $version)), true, flags: JSON_THROW_ON_ERROR);
                $identificacion = $esquema['properties']['identificacion']['properties'];
                $this->assertSame($tipo, $identificacion['tipoDte']['const'], $archivo);
                $this->assertSame($version, $identificacion['version']['const'], $archivo);
            }
        }
    }
}
