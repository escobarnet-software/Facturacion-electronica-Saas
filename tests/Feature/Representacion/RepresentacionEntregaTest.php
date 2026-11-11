<?php

use App\Dte\Firma\FirmadorClient;
use App\Dte\Hacienda\TransmisorDte;
use App\Dte\Representacion\ArchivoDte;
use App\Dte\Representacion\CodigoQr;
use App\Dte\Representacion\Entregador;
use App\Dte\Representacion\RepresentacionGrafica;
use App\Dte\Validacion\ValidadorEsquema;
use App\Jobs\EntregarDte;
use App\Mail\EntregaDte;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Smalot\PdfParser\Parser;
use Zxing\QrReader;

/*
| Terminado cuando (Paso 8): el QR decodifica a la URL correcta; el JSON adjunto es idéntico al
| transmitido (hash); el PDF de pruebas lleva la marca de agua; una instantánea visual cubre cada tipo.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->seed(CatalogosSeeder::class);
    $this->d = crearEmpresaDePrueba();
    tenancy()->establecer($this->d['empresa']->id);
    $this->empresa = $this->d['empresa']->fresh();
});

function textoPdf(string $pdf): string
{
    return (new Parser)->parseContent($pdf)->getText();
}

it('el QR de la representación decodifica a la URL de consulta pública correcta', function () {
    $dte = sellarDte(crearDteDePrueba($this->d), '2026-11-11 10:00:00');
    $datos = (new RepresentacionGrafica)->datos($dte, $this->empresa);
    $png = base64_decode(substr($datos['qr'], strlen('data:image/png;base64,')));

    $texto = (new QrReader($png, QrReader::SOURCE_TYPE_BLOB))->text();

    expect($texto)->toBe("https://admin.factura.gob.sv/consultaPublica?ambiente=00&codGen={$dte->codigo_generacion}&fechaEmi={$dte->fecha_emision->format('Y-m-d')}")
        ->and($texto)->toBe(CodigoQr::url($dte));
});

it('el PDF del ambiente de pruebas lleva la marca «SIN VALOR LEGAL — AMBIENTE DE PRUEBAS»', function () {
    $dte = sellarDte(crearDteDePrueba($this->d), '2026-11-11 10:00:00');
    $texto = textoPdf((new RepresentacionGrafica)->pdf($dte, $this->empresa));

    expect($texto)->toContain('SIN VALOR LEGAL')->toContain('AMBIENTE DE PRUEBAS')
        ->toContain($dte->codigo_generacion)->toContain($dte->numero_control)->toContain($dte->sello_recibido)
        ->toContain('Total a Pagar')->toContain('$6.50');
});

it('en producción (ambiente 01) el PDF no lleva la marca de pruebas', function () {
    $dte = sellarDte(crearDteDePrueba($this->d), '2026-11-11 10:00:00');
    $dte->forceFill(['ambiente' => '01'])->saveQuietly();

    expect(textoPdf((new RepresentacionGrafica)->pdf($dte->fresh(), $this->empresa)))->not->toContain('SIN VALOR LEGAL');
});

it('genera también el formato ticket de 80 mm', function () {
    $dte = sellarDte(crearDteDePrueba($this->d), '2026-11-11 10:00:00');
    $pdf = (new RepresentacionGrafica)->pdf($dte, $this->empresa, 'ticket');
    $paginas = (new Parser)->parseContent($pdf)->getPages();

    expect($paginas[0]->getDetails()['MediaBox'][2] ?? null)->toEqualWithDelta(226.77, 0.1)
        ->and(textoPdf($pdf))->toContain($dte->codigo_generacion);
});

it('un documento en contingencia lo indica y su archivo va sin sello', function () {
    $dte = crearDteDePrueba($this->d, 'firmado');
    $dte->forceFill([
        'json_texto' => str_replace('"tipoModelo":1,"tipoOperacion":1,"tipoContingencia":null', '"tipoModelo":2,"tipoOperacion":2,"tipoContingencia":1', $dte->json_texto),
        'operacion' => 2, 'modelo' => 2, 'estado' => 'transitorio',
    ])->save();
    $dte->forceFill(['json_original' => json_decode($dte->json_texto, true)])->save();

    expect(textoPdf((new RepresentacionGrafica)->pdf($dte->fresh(), $this->empresa)))->toContain('CONTINGENCIA')
        ->and(ArchivoDte::contenido($dte->fresh()))->not->toContain('selloRecibido')->toContain('firmaElectronica');
});

it('el Archivo DTE es el JSON firmado + firma + sello, sin alterar ningún número', function () {
    $dte = sellarDte(crearDteDePrueba($this->d), '2026-11-11 10:00:00');
    $archivo = ArchivoDte::contenido($dte);
    $doc = json_decode($archivo, true, flags: JSON_THROW_ON_ERROR);

    expect($archivo)->toStartWith(substr($dte->json_texto, 0, -1))
        ->and($doc['firmaElectronica'])->toBe($dte->jws)
        ->and($doc['selloRecibido'])->toBe($dte->sello_recibido)
        ->and($archivo)->toContain('"totalPagar":6.5');
});

it('entrega por correo: el JSON adjunto es idéntico al transmitido y archivado (hash), con el PDF', function () {
    Mail::fake();
    $dte = sellarDte(crearDteDePrueba($this->d), '2026-11-11 10:00:00');

    (new Entregador)->entregar($dte, $this->empresa, 'cliente@correo.test');
    $dte->refresh();

    $guardado = Storage::disk('local')->get(ArchivoDte::ruta($dte, $this->empresa, 'json'));
    expect(hash('sha256', $guardado))->toBe($dte->archivo_json_sha256)
        ->and($guardado)->toBe(ArchivoDte::contenido($dte))
        ->and(Storage::disk('local')->exists($dte->pdf_path))->toBeTrue()
        ->and($dte->entregado_a)->toBe('cliente@correo.test');

    Mail::assertSent(EntregaDte::class, function (EntregaDte $correo) use ($dte, $guardado) {
        $adjuntos = collect($correo->attachments());

        return $correo->hasTo('cliente@correo.test')
            && hash('sha256', $correo->json) === $dte->archivo_json_sha256
            && $correo->json === $guardado
            && str_starts_with($correo->pdf, '%PDF')
            && $adjuntos->count() === 2
            && $adjuntos->contains(fn ($a) => $a->as === "{$dte->codigo_generacion}.json")
            && $adjuntos->contains(fn ($a) => $a->as === "{$dte->codigo_generacion}.pdf");
    });
});

it('el correo solo habla de la entrega: sin publicidad, promociones ni encuestas', function () {
    $dte = sellarDte(crearDteDePrueba($this->d), '2026-11-11 10:00:00');
    $correo = new EntregaDte([
        'emisor' => 'Pupusería', 'nitEmisor' => '06140000000000', 'tipo' => 'FACTURA', 'numeroControl' => $dte->numero_control,
        'codigoGeneracion' => $dte->codigo_generacion, 'sello' => $dte->sello_recibido, 'fecha' => '11/11/2026 10:00:00',
        'total' => '$6.50', 'enlace' => 'https://ejemplo.test/dte', 'contingencia' => false,
    ], '{}', '%PDF');
    $texto = $correo->render();

    expect($texto)->toContain($dte->codigo_generacion)->toContain('Sello de recepción')->toContain('.json')->toContain('.pdf')
        ->and(mb_strtolower($texto))->not->toMatch('/promoci|oferta|descuento|encuesta|s[ií]guenos|publicidad|suscr[ií]b/');
});

it('el archivo es inmutable: no se reemplaza con otro contenido', function () {
    $dte = sellarDte(crearDteDePrueba($this->d), '2026-11-11 10:00:00');
    $archivo = new ArchivoDte;
    $archivo->guardar($dte, $this->empresa, '%PDF-1');
    $archivo->guardar($dte, $this->empresa, '%PDF-2'); // mismo JSON: no falla

    Storage::disk('local')->put(ArchivoDte::ruta($dte, $this->empresa, 'json'), '{"alterado":true}');
    expect(fn () => $archivo->guardar($dte->fresh(), $this->empresa, '%PDF-3'))->toThrow(RuntimeException::class, 'inmutable');
});

it('la versión entregada en contingencia se completa con el sello al transmitirse', function () {
    $dte = crearDteDePrueba($this->d, 'firmado');
    $archivo = new ArchivoDte;
    $archivo->guardar($dte, $this->empresa, '%PDF');

    $sellado = sellarDte($dte, '2026-11-11 12:00:00');
    $archivo->guardar($sellado, $this->empresa, '%PDF');

    expect(Storage::disk('local')->get(ArchivoDte::ruta($sellado, $this->empresa, 'json')))->toContain('"selloRecibido"');
});

it('portal público: enlace firmado sin sesión con los datos mínimos y descargas', function () {
    $dte = sellarDte(crearDteDePrueba($this->d), '2026-11-11 10:00:00');
    $url = Entregador::enlacePortal($dte);
    tenancy()->limpiar();

    $this->get($url)->assertOk()
        ->assertSee($this->empresa->razon_social)->assertSee($this->empresa->nit)
        ->assertSee($dte->codigo_generacion)->assertSee($dte->sello_recibido)
        ->assertSee('Modelo de facturación')->assertSee('Tipo de transmisión')->assertSee('data:image/png;base64', false)
        ->assertSee('SIN VALOR LEGAL');

    $json = $this->get(URL::signedRoute('dte.portal.json', ['codigo' => $dte->codigo_generacion]));
    expect($json->getContent())->toBe(ArchivoDte::contenido($dte));
    $this->get(URL::signedRoute('dte.portal.pdf', ['codigo' => $dte->codigo_generacion]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('portal público: sin firma o con firma alterada no se muestra nada', function () {
    $dte = sellarDte(crearDteDePrueba($this->d), '2026-11-11 10:00:00');
    tenancy()->limpiar();

    $this->get("/dte/{$dte->codigo_generacion}")->assertForbidden();
    $this->get(Entregador::enlacePortal($dte).'x')->assertForbidden();
});

it('instantánea visual de la representación de cada tipo', function (string $tipo) {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-11 09:30:00', 'America/El_Salvador'));
    $dte = sellarDte(crearDteDeTipo($this->d, $tipo), '2026-11-11 09:31:00');
    $html = (new RepresentacionGrafica)->html($dte, $this->empresa);
    CarbonImmutable::setTestNow();

    // Se reemplaza lo que cambia en cada corrida (códigos, sello, QR, datos de la empresa de prueba).
    $normalizado = strtr(preg_replace(
        ['/data:image\/png;base64,[A-Za-z0-9+\/=]+/', '/[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}/', '/DTE-\d{2}-[A-Z0-9]{8}-\d{15}/', '/[0-9A-F]{40}/'],
        ['[QR]', '[CODIGO]', '[NUMERO_CONTROL]', '[SELLO]'],
        $html,
    ), [$this->empresa->nit => '[NIT]', $this->empresa->razon_social => '[EMPRESA]', (string) $this->empresa->nombre_comercial => '[COMERCIAL]', (string) $this->empresa->nrc => '[NRC]']);

    $archivo = base_path("tests/__snapshots__/representacion/{$tipo}.html");
    if (! is_file($archivo) || getenv('ACTUALIZAR_SNAPSHOTS')) {
        file_put_contents($archivo, $normalizado);
    }

    expect($normalizado)->toBe(file_get_contents($archivo))
        ->and($normalizado)->toContain('DOCUMENTO TRIBUTARIO ELECTRÓNICO')->toContain('[QR]')->toContain('[CODIGO]');
})->with(['01', '03', '04', '05', '06', '07', '08', '09', '11', '14', '15']);

it('al obtener el sello se programa la entrega en la cola «entrega» (y no antes)', function () {
    config(['dte.entrega.automatica' => true]);
    Queue::fake();
    Cache::flush();
    $dte = crearDteDePrueba($this->d, 'firmado');
    Http::fake([
        'https://apitest.dtes.mh.gob.sv/seguridad/auth' => Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer t']]),
        'https://apitest.dtes.mh.gob.sv/fesv/recepciondte' => Http::response(['estado' => 'RECHAZADO', 'codigoMsg' => '020', 'descripcionMsg' => 'CALCULO INCORRECTO'], 400),
    ]);

    TransmisorDte::crear()->procesar($dte, $this->empresa, $this->d['credencial']->fresh());
    Queue::assertNothingPushed();   // rechazado: no se entrega

    $otro = crearDteDePrueba($this->d, 'firmado');
    (new TransmisorDte(new FirmadorClient, new ValidadorEsquema(config('dte.esquemas'))))
        ->aplicarRespuesta($otro, ['estado' => 'PROCESADO', 'selloRecibido' => str_repeat('B', 40), 'codigoMsg' => '001', 'fhProcesamiento' => '11/11/2026 10:00:00'], 'prueba');

    Queue::assertPushedOn('entrega', EntregarDte::class, fn ($job) => $job->dteId === $otro->id && $job->empresaId === $this->empresa->id);
});
