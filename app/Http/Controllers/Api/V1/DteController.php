<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\Idempotencia;
use App\Api\MontoApi;
use App\Dte\Emision\EmisionDte;
use App\Dte\Emision\LineaSolicitud;
use App\Dte\Eventos\DatosInvalidacion;
use App\Dte\Eventos\Invalidador;
use App\Dte\Excepciones\DteException;
use App\Dte\Modelos\Pago;
use App\Dte\Representacion\ArchivoDte;
use App\Dte\Representacion\Entregador;
use App\Dte\Representacion\RepresentacionGrafica;
use App\Dte\Validacion\ReglasHacienda as R;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\EmpresaCredencial;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * API pública v1 de documentos (ESPECIFICACION §19.3). La empresa es SIEMPRE la dueña del token.
 * La plataforma calcula los totales; con calcular=false, los totales enviados se validan con holgura de ±0.01.
 */
final class DteController
{
    /** Tipos que la API emite por ahora (los mismos que el panel). */
    public const TIPOS_EMISION = ['01', '03'];

    public function emitir(Request $request, string $tipo, EmisionDte $emision, Idempotencia $idempotencia): JsonResponse
    {
        return $idempotencia->ejecutar($request, function () use ($request, $tipo, $emision) {
            if (! in_array($tipo, self::TIPOS_EMISION, true)) {
                return response()->json(['message' => "La API emite por ahora Facturas (01) y Créditos Fiscales (03); el tipo {$tipo} aún no está disponible."], 422);
            }
            if (is_array($cliente = $request->input('cliente'))) {
                // Se aceptan NIT/NRC con guiones y DUI sin guion; se normalizan al formato de Hacienda.
                $request->merge(['cliente' => [...$cliente,
                    'num_documento' => R::documento(isset($cliente['tipo_documento']) ? (string) $cliente['tipo_documento'] : null, isset($cliente['num_documento']) ? (string) $cliente['num_documento'] : null),
                    'nrc' => R::soloDigitos(isset($cliente['nrc']) ? (string) $cliente['nrc'] : null)]]);
            }
            $datos = $request->validate([
                'establecimiento' => ['nullable', 'string', 'size:4'],
                'punto_venta' => ['nullable', 'string', 'size:4'],
                'lineas' => ['required', 'array', 'min:1', 'max:2000'],
                'lineas.*.descripcion' => R::texto('descripcion'),
                'lineas.*.cantidad' => ['required'],
                'lineas.*.precio' => ['required'],
                'lineas.*.descuento' => ['nullable'],
                'lineas.*.tipo_venta' => ['nullable', 'in:gravada,exenta,no_sujeta'],
                'lineas.*.tipo_item' => ['nullable', 'integer', 'in:1,2,3,4'],
                'lineas.*.uni_medida' => ['nullable', 'integer'],
                'lineas.*.codigo' => ['nullable', 'string', 'max:25'],
                'cliente' => ['nullable', 'array'],
                // Mismas reglas de Hacienda que el panel (esquemas y catálogos oficiales).
                'cliente.tipo_documento' => ['required_with:cliente', ...array_slice(R::tipoDocumento(), 1)],
                'cliente.num_documento' => ['required_with:cliente', ...array_slice(R::numeroDocumento($request->input('cliente.tipo_documento')), 1)],
                'cliente.nombre' => ['required_with:cliente', ...array_slice(R::texto('nombre'), 1)],
                'cliente.nrc' => R::nrc(false),
                'cliente.cod_actividad' => R::actividad(false),
                'cliente.desc_actividad' => R::texto('desc_actividad', false),
                'cliente.direccion' => ['nullable', 'array'],
                'cliente.direccion.departamento' => R::departamento(false),
                'cliente.direccion.municipio' => R::municipio($request->input('cliente.direccion.departamento'), false),
                'cliente.direccion.distrito' => R::distrito($request->input('cliente.direccion.departamento'), false),
                'cliente.direccion.complemento' => R::texto('complemento', false),
                'cliente.telefono' => R::telefono(false),
                'cliente.correo' => R::correo(false),
                'pagos' => ['nullable', 'array'],
                'pagos.*.codigo' => ['required', 'string', 'size:2'],
                'pagos.*.monto' => ['nullable'],
                'pagos.*.referencia' => ['nullable', 'string', 'max:50'],
                'pagos.*.plazo' => ['nullable', 'string', 'size:2'],
                'pagos.*.periodo' => ['nullable', 'integer'],
                'condicion_operacion' => ['nullable', 'integer', 'in:1,2,3'],
                'observaciones' => R::texto('observaciones', false),
                'correo' => R::correo(false),
                'calcular' => ['nullable', 'boolean'],
                'totales' => ['required_if:calcular,false', 'array'],
                'totales.total_pagar' => ['nullable'],
                'totales.total_gravada' => ['nullable'],
                'totales.total_iva' => ['nullable'],
            ], R::mensajes(['cliente.num_documento' => 'documento', 'cliente.nrc' => 'nrc', 'cliente.cod_actividad' => 'actividad', 'cliente.correo' => 'correo',
                'cliente.telefono' => 'telefono', 'cliente.direccion.departamento' => 'departamento', 'cliente.direccion.municipio' => 'municipio',
                'cliente.direccion.distrito' => 'distrito', 'cliente.tipo_documento' => 'tipoDocumento', 'correo' => 'correo']));

            $empresa = $this->empresa($request);
            [$establecimiento, $puntoVenta] = $this->puntoDeVenta($datos['establecimiento'] ?? null, $datos['punto_venta'] ?? null);
            $lineas = array_map(fn (array $l, int $i) => new LineaSolicitud(
                descripcion: (string) $l['descripcion'],
                cantidad: MontoApi::texto($l['cantidad'], "lineas.{$i}.cantidad"),
                precioSinIva: MontoApi::texto($l['precio'], "lineas.{$i}.precio"),
                descuento: MontoApi::texto($l['descuento'] ?? '0', "lineas.{$i}.descuento"),
                tipoVenta: (string) ($l['tipo_venta'] ?? 'gravada'),
                tipoItem: (int) ($l['tipo_item'] ?? 1),
                uniMedida: (int) ($l['uni_medida'] ?? 59),
                codigo: $l['codigo'] ?? null,
            ), array_values($datos['lineas']), array_keys(array_values($datos['lineas'])));
            $pagos = array_map(fn (array $p, int $i) => new Pago((string) $p['codigo'], isset($p['monto']) ? MontoApi::texto($p['monto'], "pagos.{$i}.monto") : null, $p['referencia'] ?? null, $p['plazo'] ?? null, isset($p['periodo']) ? (int) $p['periodo'] : null), array_values($datos['pagos'] ?? []), array_keys(array_values($datos['pagos'] ?? [])));
            $cliente = isset($datos['cliente']) ? $this->cliente($datos['cliente']) : null;

            if (($datos['calcular'] ?? true) === false) {
                $this->validarTotales($emision->previsualizar($empresa, $establecimiento, $puntoVenta, $tipo, $lineas, $cliente), (array) $datos['totales']);
            }

            $dte = $emision->emitir($empresa, $establecimiento, $puntoVenta, $tipo, $lineas, $cliente, $pagos,
                (int) ($datos['condicion_operacion'] ?? 1), $datos['observaciones'] ?? null, $datos['correo'] ?? null);

            return response()->json(['data' => $this->recurso($dte)], 201);
        });
    }

    public function mostrar(string $codigo): JsonResponse
    {
        return response()->json(['data' => $this->recurso($this->buscar($codigo))]);
    }

    public function pdf(Request $request, string $codigo, RepresentacionGrafica $representacion): Response
    {
        $dte = $this->buscar($codigo);
        abort_if($dte->jws === null, 404, 'El documento aún no está firmado.');

        return response($representacion->pdf($dte, $this->empresa($request), $request->query('formato') === 'ticket' ? 'ticket' : 'carta'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.ArchivoDte::nombre($dte, 'pdf').'"',
        ]);
    }

    public function json(string $codigo): Response
    {
        $dte = $this->buscar($codigo);
        abort_if($dte->jws === null, 404, 'El documento aún no está firmado.');

        return response(ArchivoDte::contenido($dte), 200, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public function invalidar(Request $request, string $codigo, Invalidador $invalidador, Idempotencia $idempotencia): JsonResponse
    {
        return $idempotencia->ejecutar($request, function () use ($request, $codigo, $invalidador) {
            $datos = $request->validate([
                'tipo_anulacion' => ['required', 'integer', 'in:1,2,3'],
                'motivo' => ['nullable', 'required_if:tipo_anulacion,3', 'string', 'max:250'],
                'codigo_generacion_reemplazo' => ['nullable', 'string', 'size:36'],
                'solicita' => ['required', 'array'],
                'solicita.nombre' => ['required', 'string', 'max:100'],
                'solicita.tipo_documento' => ['required', 'string', 'max:2'],
                'solicita.num_documento' => ['required', 'string', 'max:20'],
            ]);
            $dte = $this->buscar($codigo);
            $empresa = $this->empresa($request);

            $evento = $invalidador->invalidar($dte, $empresa, EmpresaCredencial::query()->where('ambiente', $dte->ambiente)->firstOrFail(), new DatosInvalidacion(
                (int) $datos['tipo_anulacion'],
                (string) $empresa->getAttribute('responsable_nombre'), (string) ($empresa->getAttribute('responsable_tipo_doc') ?? '13'), (string) $empresa->getAttribute('responsable_num_doc'),
                (string) $datos['solicita']['nombre'], (string) $datos['solicita']['tipo_documento'], (string) $datos['solicita']['num_documento'],
                $datos['motivo'] ?? null,
                isset($datos['codigo_generacion_reemplazo']) ? strtoupper((string) $datos['codigo_generacion_reemplazo']) : null,
            ));

            return response()->json(['data' => [...$this->recurso($dte->refresh()), 'evento_invalidacion' => [
                'codigo_generacion' => $evento->codigo_generacion, 'sello_recibido' => $evento->sello_recibido,
            ]]]);
        });
    }

    /** @return array<string, mixed> */
    private function recurso(Dte $dte): array
    {
        return [
            'codigo_generacion' => $dte->codigo_generacion,
            'numero_control' => $dte->numero_control,
            'tipo' => $dte->tipo,
            'ambiente' => $dte->ambiente,
            'estado' => $dte->estado->value,
            'estado_descripcion' => $dte->estado->etiqueta(),
            'fecha_emision' => $dte->fecha_emision->format('Y-m-d'),
            'sello_recibido' => $dte->sello_recibido,
            'total_gravada' => (string) $dte->getRawOriginal('total_gravada'),
            'total_iva' => (string) $dte->getRawOriginal('total_iva'),
            'total_pagar' => (string) $dte->getRawOriginal('total_pagar'),
            'enlaces' => [
                'pdf' => route('api.v1.dte.pdf', $dte->codigo_generacion),
                'json' => route('api.v1.dte.json', $dte->codigo_generacion),
                'portal' => Entregador::enlacePortal($dte),
            ],
        ];
    }

    private function buscar(string $codigo): Dte
    {
        $dte = Dte::query()->where('codigo_generacion', strtoupper($codigo))->first();
        abort_if($dte === null, 404, 'No existe un documento con ese código de generación.');

        return $dte;
    }

    private function empresa(Request $request): Empresa
    {
        $empresa = $request->user();
        abort_unless($empresa instanceof Empresa, 401);

        return $empresa;
    }

    /** @return array{0: Establecimiento, 1: PuntoVenta} */
    private function puntoDeVenta(?string $codEstable, ?string $codPunto): array
    {
        $establecimiento = Establecimiento::query()->when($codEstable, fn ($q) => $q->where('cod_estable_mh', $codEstable))->orderBy('id')->first();
        if ($establecimiento === null) {
            throw new DteException('No existe el establecimiento indicado.');
        }
        $puntoVenta = PuntoVenta::query()->where('establecimiento_id', $establecimiento->id)
            ->when($codPunto, fn ($q) => $q->where('cod_punto_venta_mh', $codPunto))->orderBy('id')->first();
        if ($puntoVenta === null) {
            throw new DteException('No existe el punto de venta indicado en ese establecimiento.');
        }

        return [$establecimiento, $puntoVenta];
    }

    /** @param array<string, mixed> $c */
    private function cliente(array $c): Cliente
    {
        $cliente = Cliente::query()->firstOrNew(['tipo_documento' => (string) $c['tipo_documento'], 'num_documento' => (string) $c['num_documento']]);
        $cliente->fill(array_filter([
            'nombre' => $c['nombre'] ?? null, 'nrc' => $c['nrc'] ?? null, 'cod_actividad' => $c['cod_actividad'] ?? null,
            'desc_actividad' => $c['desc_actividad'] ?? null, 'direccion' => $c['direccion'] ?? null,
            'telefono' => $c['telefono'] ?? null, 'correo' => $c['correo'] ?? null,
        ], fn ($v) => $v !== null))->save();

        return $cliente;
    }

    /**
     * @param  array<string, mixed>  $resumen
     * @param  array<string, mixed>  $enviados
     */
    private function validarTotales(array $resumen, array $enviados): void
    {
        $pares = ['total_pagar' => 'totalPagar', 'total_gravada' => 'totalGravada', 'total_iva' => 'totalIva'];
        if (! isset($enviados['total_pagar'])) {
            throw new DteException('Con calcular=false debe enviar al menos totales.total_pagar.');
        }
        foreach ($pares as $campo => $clave) {
            if (! isset($enviados[$campo]) || ! array_key_exists($clave, $resumen)) {
                continue;
            }
            $calculado = Decimal::of(is_float($resumen[$clave]) ? (string) json_encode($resumen[$clave]) : (string) $resumen[$clave]);
            $enviado = Decimal::of(MontoApi::texto($enviados[$campo], "totales.{$campo}"));
            if ($calculado->sub($enviado)->abs()->compareTo('0.01') > 0) {
                throw new DteException("Los totales enviados no cuadran con el cálculo: {$campo} calculado {$calculado->toFixed(2)}, enviado {$enviado->toFixed(2)}.");
            }
        }
    }
}
