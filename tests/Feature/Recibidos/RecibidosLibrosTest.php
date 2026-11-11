<?php

use App\Dte\Contribuyente\CreditoFiscalBuilder;
use App\Dte\Contribuyente\DocumentoContribuyente;
use App\Dte\Contribuyente\Linea;
use App\Dte\Contribuyente\ReceptorContribuyente;
use App\Dte\Excepciones\DteException;
use App\Dte\Modelos\Emisor;
use App\Dte\Recibidos\ImportadorRecibidos;
use App\Dte\Reportes\ExportadorLibros;
use App\Dte\Reportes\LibrosIva;
use App\Models\Dte;
use App\Models\DteRecibido;
use App\Models\Empresa;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/*
| Terminado cuando (Paso 11): los totales de los libros cuadran con la suma de los documentos del período.
*/

/** CCF que un PROVEEDOR emite a nombre de la empresa, como archivo completo (JWS + sello). */
function ccfRecibido(Empresa $empresa, string $precio = '200.00', int $correlativo = 1, ?string $nitReceptor = null): array
{
    $proveedor = new Emisor(
        nit: '06142803901121', nrc: '2345678', nombre: 'PROVEEDOR DE PRUEBA, S.A. DE C.V.', codActividad: '46900',
        descActividad: 'Venta al por mayor', nombreComercial: 'Proveedor', departamento: '06', municipio: '23',
        distrito: '14', complemento: 'San Salvador', telefono: '22224444', correo: 'dte@proveedor.test', codEstable: 'M001', codPuntoVenta: 'P001',
    );
    $receptor = new ReceptorContribuyente($nitReceptor ?? $empresa->nit, (string) $empresa->nrc, $empresa->razon_social, '46900', 'Venta al por mayor', '06', '23', '14', 'San Salvador', 'compras@empresa.test');
    $fecha = CarbonImmutable::now('America/El_Salvador')->toDateTimeImmutable();
    $armado = (new CreditoFiscalBuilder($proveedor))->construir(new DocumentoContribuyente([Linea::gravada('Insumos', '1', $precio)], $receptor, $correlativo, $fecha));

    $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $doc = $armado->documento();
    $jws = $b64('{"alg":"RS512"}').'.'.$b64($armado->json).'.'.$b64('firma-falsa');

    return [...$doc, 'firmaElectronica' => $jws, 'selloRecibido' => strtoupper(substr(hash('sha256', $armado->codigoGeneracion), 0, 40))];
}

/** Guarda el DTE emitido con sus totales (como EmisionDte) y lo deja sellado. */
function emitidoParaLibro(array $d, string $tipo, string $estado = 'transmitido'): Dte
{
    $dte = crearDteDeTipo($d, $tipo);
    $r = $dte->json_original['resumen'];
    $iva20 = array_values(array_filter((array) ($r['tributos'] ?? []), fn ($t) => ($t['codigo'] ?? null) === '20'))[0]['valor'] ?? 0;
    $texto = fn ($v) => is_float($v) ? (string) json_encode($v) : (string) $v;
    $dte->forceFill([
        'receptor_nombre' => $dte->json_original['receptor']['nombre'] ?? null,
        'total_no_suj' => $texto($r['totalNoSuj'] ?? 0), 'total_exenta' => $texto($r['totalExenta'] ?? 0),
        'total_gravada' => $texto($r['totalGravada'] ?? 0), 'total_pagar' => $texto($r['totalPagar'] ?? 0),
        'total_iva' => $texto($tipo === '01' ? ($r['totalIva'] ?? 0) : $iva20),
    ])->save();
    $dte = sellarDte($dte, now('America/El_Salvador')->format('Y-m-d H:i:s'));
    if ($estado !== 'transmitido') {
        $dte->forceFill(['estado' => $estado])->saveQuietly();
    }

    return $dte->refresh();
}

beforeEach(function () {
    $this->d = crearEmpresaDePrueba();
    tenancy()->establecer($this->d['empresa']->id);
    $this->importador = app(ImportadorRecibidos::class);
    $this->desde = now('America/El_Salvador')->startOfMonth()->toDateString();
    $this->hasta = now('America/El_Salvador')->endOfMonth()->toDateString();
});

it('importa un CCF recibido válido con sus totales exactos', function () {
    $r = $this->importador->importar($this->d['empresa'], json_encode(ccfRecibido($this->d['empresa'])))->refresh();

    expect($r->tipo)->toBe('03')
        ->and($r->getRawOriginal('total_gravada'))->toBe('200.00')
        ->and($r->getRawOriginal('total_iva'))->toBe('26.00')
        ->and($r->getRawOriginal('total_pagar'))->toBe('226.00')
        ->and($r->firma_coincide)->toBeTrue()
        ->and($r->verificado_publico)->toBeNull();
});

it('es idempotente: el mismo documento no se registra dos veces', function () {
    $json = json_encode(ccfRecibido($this->d['empresa']));
    $this->importador->importar($this->d['empresa'], $json);
    $this->importador->importar($this->d['empresa'], $json);

    expect(DteRecibido::query()->count())->toBe(1);
});

it('rechaza un documento alterado después de firmado', function () {
    $doc = ccfRecibido($this->d['empresa']);
    $doc['resumen']['totalPagar'] = 2260.0;

    expect(fn () => $this->importador->importar($this->d['empresa'], json_encode($doc)))
        ->toThrow(DteException::class, 'puede haber sido alterado');
});

it('rechaza documentos sin sello, de otra empresa o emitidos por la propia empresa', function () {
    $sinSello = ccfRecibido($this->d['empresa']);
    unset($sinSello['selloRecibido']);
    expect(fn () => $this->importador->importar($this->d['empresa'], json_encode($sinSello)))->toThrow(DteException::class, 'sello');

    $ajeno = ccfRecibido($this->d['empresa'], nitReceptor: '06140101851014');
    expect(fn () => $this->importador->importar($this->d['empresa'], json_encode($ajeno)))->toThrow(DteException::class, 'nombre de esta empresa');

    $propio = crearDteDePrueba($this->d);
    $doc = [...$propio->json_original, 'firmaElectronica' => 'a.b.c', 'selloRecibido' => str_repeat('A', 40)];
    expect(fn () => $this->importador->importar($this->d['empresa'], json_encode($doc)))->toThrow(DteException::class, 'misma empresa');

    expect(fn () => $this->importador->importar($this->d['empresa'], 'no es json'))->toThrow(DteException::class, 'JSON válido');
    expect(DteRecibido::query()->count())->toBe(0);
});

it('verifica en la consulta pública solo si está activado (siempre con Http::fake)', function () {
    config(['dte.recibidos.verificar_publico' => true]);
    $doc = ccfRecibido($this->d['empresa']);
    Http::fake(['admin.factura.gob.sv/*' => Http::response('<html>Sello '.$doc['selloRecibido'].'</html>')]);

    $r = $this->importador->importar($this->d['empresa'], json_encode($doc));

    expect($r->verificado_publico)->toBeTrue();
    Http::assertSentCount(1);
});

it('importa los adjuntos .json que llegan al buzón de correo de la empresa', function () {
    $token = str_repeat('b', 40);
    tenancy()->sinAislamiento(fn () => Empresa::query()->whereKey($this->d['empresa']->id)->update(['buzon_token' => $token]));
    $adjunto = base64_encode((string) json_encode(ccfRecibido($this->d['empresa'])));
    $mime = "From: dte@proveedor.test\r\nTo: buzon@escobarnet.test\r\nSubject: DTE\r\nMIME-Version: 1.0\r\n"
        ."Content-Type: multipart/mixed; boundary=\"XYZ\"\r\n\r\n--XYZ\r\nContent-Type: text/plain\r\n\r\nAdjunto su documento.\r\n"
        ."--XYZ\r\nContent-Type: application/json; name=\"doc.json\"\r\nContent-Disposition: attachment; filename=\"doc.json\"\r\n"
        ."Content-Transfer-Encoding: base64\r\n\r\n".chunk_split($adjunto, 76, "\r\n")."--XYZ--\r\n";
    tenancy()->limpiar();

    $this->call('POST', '/buzon/'.$token, [], [], [], ['CONTENT_TYPE' => 'message/rfc822'], $mime)
        ->assertOk()->assertJsonCount(1, 'importados');
    $this->call('POST', '/buzon/'.str_repeat('x', 40), [], [], [], [], $mime)->assertNotFound();

    tenancy()->establecer($this->d['empresa']->id);
    expect(DteRecibido::query()->first()?->origen)->toBe('correo');
});

it('los totales de los libros cuadran con la suma de los documentos del período', function () {
    $fe1 = emitidoParaLibro($this->d, '01');
    $fe2 = emitidoParaLibro($this->d, '01');
    $ccf = emitidoParaLibro($this->d, '03');
    $nc = emitidoParaLibro($this->d, '05');
    $nd = emitidoParaLibro($this->d, '06');
    $fse = emitidoParaLibro($this->d, '14');
    emitidoParaLibro($this->d, '03', 'invalidado');   // no cuenta
    crearDteDeTipo($this->d, '03');                    // borrador: no cuenta
    $compra1 = $this->importador->importar($this->d['empresa'], json_encode(ccfRecibido($this->d['empresa'], '200.00', 1)));
    $compra2 = $this->importador->importar($this->d['empresa'], json_encode(ccfRecibido($this->d['empresa'], '50.00', 2)));

    $libros = new LibrosIva;
    $s = fn (string $c, Dte|DteRecibido ...$docs) => Decimal::sum(array_map(fn ($x) => (string) $x->getRawOriginal($c), $docs));

    $consumidor = $libros->ventasConsumidor($this->desde, $this->hasta);
    expect($consumidor['totales']['gravadas'])->toBe($s('total_gravada', $fe1, $fe2)->toFixed(2))
        ->and($consumidor['filas'])->toHaveCount(1)
        ->and($consumidor['filas'][0][3])->toBe('2');

    $contrib = $libros->ventasContribuyentes($this->desde, $this->hasta);
    expect($contrib['filas'])->toHaveCount(3)
        ->and($contrib['totales']['gravadas'])->toBe($s('total_gravada', $ccf, $nd)->sub($s('total_gravada', $nc))->toFixed(2))
        ->and($contrib['totales']['debito'])->toBe($s('total_iva', $ccf, $nd)->sub($s('total_iva', $nc))->toFixed(2))
        ->and($contrib['totales']['debito'])->toBe('13.00'); // 13 (CCF) + 1.30 (ND) − 1.30 (NC)

    $compras = $libros->compras($this->desde, $this->hasta);
    expect($compras['totales']['credito'])->toBe($s('total_iva', $compra1, $compra2)->toFixed(2))
        ->and($compras['totales']['credito'])->toBe('32.50')
        ->and($compras['totales']['excluidos'])->toBe('50.00')
        ->and($compras['filas'])->toHaveCount(3);

    $f07 = $libros->resumenF07($this->desde, $this->hasta);
    $debito = $s('total_iva', $fe1, $fe2)->add('13.00');
    expect($f07['total_debito_fiscal'])->toBe($debito->toFixed(2))
        ->and($f07['credito_fiscal'])->toBe('32.50');
    $neto = $debito->sub('32.50');
    expect($neto->isNegative() ? $f07['remanente_credito'] : $f07['impuesto_a_pagar'])->toBe($neto->abs()->toFixed(2));

    unset($fse);
});

it('exporta los libros en CSV y en Excel', function () {
    emitidoParaLibro($this->d, '03');
    $exportador = new ExportadorLibros(new LibrosIva);

    $csv = $exportador->csv('contribuyentes', $this->desde, $this->hasta);
    expect($csv)->toContain('Débito fiscal')->toContain('13.00')->toContain('TOTALES');

    $ruta = $exportador->xlsx('contribuyentes', $this->desde, $this->hasta);
    expect(file_exists($ruta))->toBeTrue()->and(filesize($ruta))->toBeGreaterThan(1000);
    @unlink($ruta);
});

it('el panel muestra los recibidos y los reportes y descarga el libro', function () {
    $this->actingAs($this->d['usuario']);

    $this->get(route('panel.recibidos'))->assertOk()->assertSee('Documentos recibidos');
    $this->get(route('panel.reportes'))->assertOk()->assertSee('Libros de IVA');
    $this->get(route('panel.reportes.descargar', ['libro' => 'consumidor', 'formato' => 'csv', 'desde' => $this->desde, 'hasta' => $this->hasta]))
        ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
});
