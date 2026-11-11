<?php

declare(strict_types=1);

namespace App\Dte\Pruebas;

use App\Dte\Correlativos\Correlativos;
use App\Dte\Eventos\DatosInvalidacion;
use App\Dte\Eventos\GestorContingencia;
use App\Dte\Eventos\Invalidador;
use App\Dte\Eventos\OperacionesEspeciales;
use App\Dte\Eventos\Retornador;
use App\Dte\Hacienda\CredencialesBloqueadasException;
use App\Dte\Hacienda\TransmisorDte;
use App\Dte\Validacion\ValidadorEsquema;
use App\Enums\EstadoDte;
use App\Models\Contingencia;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\EmpresaCredencial;
use App\Models\Establecimiento;
use App\Models\Evento;
use App\Models\PruebaMinima;
use App\Models\PuntoVenta;
use Carbon\CarbonImmutable;
use Closure;
use LogicException;
use Throwable;

/**
 * Ejecuta las pruebas mínimas (Manual Funcional XXIV) en el ambiente de pruebas.
 *
 *  - Simulación (dry-run): arma y valida contra el esquema oficial la cantidad exacta de cada tipo,
 *    sin escribir en la base de datos ni transmitir.
 *  - Real: firma y transmite uno a uno; guarda el avance por tipo, así que si se corta se reanuda
 *    donde quedó. Se detiene tras varios fallos seguidos o si las credenciales se bloquean.
 *  - Orden: primero los documentos (NC/ND usan un CCF ya sellado), luego los eventos.
 */
final class EjecutorPruebasMinimas
{
    public const ORDEN_DOCUMENTOS = ['01', '03', '04', '05', '06', '07', '08', '09', '11', '14', '15'];

    public const ORDEN_EVENTOS = ['invalidacion', 'retorno', 'operaciones_especiales', 'contingencia'];

    public function __construct(
        private readonly GeneradorDocumentosPrueba $generador,
        private readonly Correlativos $correlativos,
    ) {}

    /**
     * @param  list<string>|null  $tipos
     * @return array<string, int> requeridas por tipo (documentos y eventos)
     */
    public static function requeridas(?array $tipos = null): array
    {
        // «+» conserva las claves: el spread (...) renumeraría las numéricas ('11' → 0, 1, …).
        $todas = (array) config('dte.pruebas_minimas.documentos') + (array) config('dte.pruebas_minimas.eventos');

        return $tipos === null ? $todas : array_intersect_key($todas, array_flip($tipos));
    }

    /**
     * Simulación: cantidad de documentos válidos generados por tipo (los eventos se omiten).
     *
     * @param  list<string>  $tipos
     * @param  (Closure(string, int, int): void)|null  $avance
     * @return array<string, array{generados: int, validos: int, errores: list<string>}>
     */
    public function simular(Empresa $empresa, array $tipos, ?Closure $avance = null): array
    {
        [$establecimiento, $puntoVenta] = $this->ubicacion();
        $validador = new ValidadorEsquema((string) config('dte.esquemas'));
        $fecha = CarbonImmutable::now((string) config('dte.zona_horaria'))->toDateTimeImmutable();
        $resultado = [];

        foreach (array_intersect(self::ORDEN_DOCUMENTOS, $tipos) as $tipo) {
            $requeridas = self::requeridas()[$tipo];
            $resultado[$tipo] = ['generados' => 0, 'validos' => 0, 'errores' => []];
            for ($n = 1; $n <= $requeridas; $n++) {
                $doc = $this->generador->armar($empresa, $establecimiento, $puntoVenta, $tipo, $n, $n, $fecha);
                $errores = $validador->validar($doc['json'], $tipo, $doc['version']);
                $resultado[$tipo]['generados']++;
                $errores === [] ? $resultado[$tipo]['validos']++ : $resultado[$tipo]['errores'][] = json_encode($errores, JSON_UNESCAPED_UNICODE);
                $avance && $avance($tipo, $n, $requeridas);
            }
        }

        return $resultado;
    }

    /**
     * Ejecución real (transmite al ambiente de pruebas). Devuelve el avance por tipo.
     *
     * @param  list<string>  $tipos
     * @param  (Closure(string, int, int): void)|null  $avance
     * @return array<string, PruebaMinima>
     */
    public function ejecutar(Empresa $empresa, EmpresaCredencial $credencial, array $tipos, ?Closure $avance = null): array
    {
        if ($credencial->ambiente !== '00' || $empresa->ambiente !== '00') {
            throw new LogicException('Las pruebas mínimas solo se ejecutan en el ambiente de pruebas (00).');
        }
        $filas = [];
        foreach (self::requeridas($tipos) as $tipo => $requeridas) {
            $filas[$tipo] = PruebaMinima::query()->firstOrCreate(['tipo' => (string) $tipo], ['requeridas' => $requeridas, 'exitosas' => 0, 'fallidas' => 0, 'estado' => 'pendiente']);
        }

        $orden = [...array_intersect(self::ORDEN_DOCUMENTOS, $tipos), ...array_intersect(self::ORDEN_EVENTOS, $tipos)];
        foreach ($orden as $tipo) {
            $fila = $filas[$tipo];
            if (! $this->correr($fila, $empresa, $credencial, $avance)) {
                break; // detenida: revisar antes de insistir
            }
        }

        return array_map(fn (PruebaMinima $f) => $f->refresh(), $filas);
    }

    private function correr(PruebaMinima $fila, Empresa $empresa, EmpresaCredencial $credencial, ?Closure $avance): bool
    {
        $maxFallos = (int) config('dte.pruebas_minimas.max_fallos_seguidos', 5);
        $fallosSeguidos = 0;
        $fila->forceFill(['estado' => 'en_curso'])->save();

        while ($fila->exitosas < $fila->requeridas) {
            try {
                $ok = in_array($fila->tipo, self::ORDEN_DOCUMENTOS, true)
                    ? $this->unDocumento($fila->tipo, $fila->exitosas + 1, $empresa, $credencial)
                    : $this->unEvento($fila->tipo, $empresa, $credencial);
                $error = $ok ? null : 'Hacienda no otorgó el sello (ver la bitácora del documento).';
            } catch (CredencialesBloqueadasException $e) {
                $fila->forceFill(['estado' => 'detenida', 'ultimo_error' => $e->getMessage()])->save();

                return false;
            } catch (Throwable $e) {
                $ok = false;
                $error = $e->getMessage();
            }

            if ($ok) {
                $fila->increment('exitosas');
                $fallosSeguidos = 0;
            } else {
                $fila->increment('fallidas');
                $fila->forceFill(['ultimo_error' => $error])->save();
                if (++$fallosSeguidos >= $maxFallos) {
                    $fila->forceFill(['estado' => 'detenida'])->save();

                    return false;
                }
            }
            $avance && $avance($fila->tipo, $fila->exitosas, $fila->requeridas);
        }

        $fila->forceFill(['estado' => 'completada'])->save();

        return true;
    }

    private function unDocumento(string $tipo, int $n, Empresa $empresa, EmpresaCredencial $credencial): bool
    {
        [$establecimiento, $puntoVenta] = $this->ubicacion();
        $fecha = CarbonImmutable::now((string) config('dte.zona_horaria'));
        $relacionado = in_array($tipo, ['05', '06'], true)
            ? Dte::query()->where('tipo', '03')->whereNotNull('sello_recibido')->whereIn('estado', [EstadoDte::Transmitido->value, EstadoDte::Observado->value, EstadoDte::Ajustado->value])->latest('id')->first()
            : null;
        if (in_array($tipo, ['05', '06'], true) && $relacionado === null) {
            throw new LogicException('Las notas de crédito y débito necesitan un Crédito Fiscal sellado: ejecute antes las pruebas del tipo 03.');
        }

        $correlativo = $this->correlativos->siguiente($empresa, $tipo, $establecimiento, $puntoVenta, $fecha);
        $doc = $this->generador->armar($empresa, $establecimiento, $puntoVenta, $tipo, $n, $correlativo, $fecha->toDateTimeImmutable(), $relacionado);
        $dte = Dte::query()->create([
            'establecimiento_id' => $establecimiento->id, 'punto_venta_id' => $puntoVenta->id, 'tipo' => $tipo, 'version' => $doc['version'],
            'ambiente' => '00', 'codigo_generacion' => $doc['codigo'], 'numero_control' => $doc['numero'],
            'fecha_emision' => $fecha->format('Y-m-d'), 'hora_emision' => $fecha->format('H:i:s'), 'estado' => EstadoDte::Borrador,
            'json_texto' => $doc['json'], 'json_original' => json_decode($doc['json'], true, flags: JSON_THROW_ON_ERROR),
        ]);

        $resultado = TransmisorDte::crear()->procesar($dte, $empresa, $credencial);

        return $resultado->sello_recibido !== null;
    }

    private function unEvento(string $tipo, Empresa $empresa, EmpresaCredencial $credencial): bool
    {
        $responsable = [(string) $empresa->getAttribute('responsable_nombre'), (string) ($empresa->getAttribute('responsable_tipo_doc') ?? '13'), (string) $empresa->getAttribute('responsable_num_doc')];

        return match ($tipo) {
            'invalidacion' => $this->conFacturaSellada($empresa, $credencial, fn (Dte $fe) => (new Invalidador)->invalidar(
                $fe, $empresa, $credencial, new DatosInvalidacion(2, ...[...$responsable, ...$responsable]),
            )->estado === 'procesado'),
            'retorno' => $this->conFacturaSellada($empresa, $credencial, fn (Dte $fe) => (new Retornador)->registrar(
                $fe, $empresa, $credencial, [['descripcion' => 'Devolución de prueba', 'cantidad' => '1', 'precio' => '0.01']],
            )->estado === 'procesado'),
            'operaciones_especiales' => (new OperacionesEspeciales)->registrar($empresa, $credencial, [[
                'tipoDocumento' => '02', 'fechaEmision' => CarbonImmutable::now((string) config('dte.zona_horaria'))->format('Y-m-d'),
                'cantidad' => 1, 'descripcion' => 'Venta simplificada de prueba', 'docDel' => '1', 'docAl' => '1', 'precioUni' => '1.13', 'ventaGravada' => '1.13',
            ]])->estado === 'procesado',
            'contingencia' => $this->unaContingencia($empresa, $credencial),
            default => throw new LogicException("Evento sin prueba: {$tipo}"),
        };
    }

    /** Emite una Factura de prueba sellada y le aplica el evento (cada evento usa su propia factura). */
    private function conFacturaSellada(Empresa $empresa, EmpresaCredencial $credencial, Closure $evento): bool
    {
        $n = Dte::query()->where('tipo', '01')->count() + 1;
        if (! $this->unDocumento('01', $n, $empresa, $credencial)) {
            return false;
        }

        return $evento(Dte::query()->where('tipo', '01')->latest('id')->firstOrFail());
    }

    /** Una contingencia completa de prueba: 1 factura en contingencia, el evento y su lote. */
    private function unaContingencia(Empresa $empresa, EmpresaCredencial $credencial): bool
    {
        [$establecimiento, $puntoVenta] = $this->ubicacion();
        $gestor = app(GestorContingencia::class);
        $fecha = CarbonImmutable::now((string) config('dte.zona_horaria'));
        $n = Dte::query()->where('tipo', '01')->count() + 1;
        $correlativo = $this->correlativos->siguiente($empresa, '01', $establecimiento, $puntoVenta, $fecha);
        $doc = $this->generador->armar($empresa, $establecimiento, $puntoVenta, '01', $n, $correlativo, $fecha->toDateTimeImmutable());
        $dte = Dte::query()->create([
            'establecimiento_id' => $establecimiento->id, 'punto_venta_id' => $puntoVenta->id, 'tipo' => '01', 'version' => $doc['version'],
            'ambiente' => '00', 'codigo_generacion' => $doc['codigo'], 'numero_control' => $doc['numero'],
            'fecha_emision' => $fecha->format('Y-m-d'), 'hora_emision' => $fecha->format('H:i:s'), 'estado' => EstadoDte::Transitorio,
            'json_texto' => $doc['json'], 'json_original' => json_decode($doc['json'], true, flags: JSON_THROW_ON_ERROR),
        ]);

        $contingencia = $gestor->registrar($dte, $empresa, $credencial, GestorContingencia::POR_FALLA_DE_HACIENDA);
        $contingencia->forceFill(['estado' => Contingencia::CERRADA, 'fin' => now()])->save();
        $gestor->procesar($empresa, $credencial);

        return Evento::query()->whereKey($contingencia->refresh()->evento_id)->value('estado') === 'procesado';
    }

    /** @return array{0: Establecimiento, 1: PuntoVenta} */
    private function ubicacion(): array
    {
        $puntoVenta = PuntoVenta::query()->orderBy('id')->firstOrFail();

        return [Establecimiento::query()->findOrFail($puntoVenta->establecimiento_id), $puntoVenta];
    }
}
