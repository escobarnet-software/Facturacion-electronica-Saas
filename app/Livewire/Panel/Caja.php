<?php

namespace App\Livewire\Panel;

use App\Dte\Emision\EmisionDte;
use App\Dte\Emision\LineaSolicitud;
use App\Dte\Excepciones\DteException;
use App\Dte\Modelos\Pago;
use App\Enums\EstadoDte;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\Usuario;
use App\Support\Decimal;
use App\Tenancy\Tenancy;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Caja rápida (POS): Factura a consumidor final escaneando o escribiendo el código.
 * Si Hacienda no responde, el documento entra en contingencia y se entrega igual (ticket de 80 mm).
 */
#[Layout('layouts.panel')]
#[Title('Caja rápida')]
class Caja extends Component
{
    public string $codigo = '';

    /** @var list<array{productoId: int, descripcion: string, cantidad: string, precio: string, codigo: string|null, tipoItem: int, uniMedida: int}> */
    public array $carrito = [];

    public string $formaPago = '01';

    public ?string $mensaje = null;

    public ?string $error = null;

    public ?int $ultimoDteId = null;

    public function mount(): void
    {
        Gate::authorize('emitir');
    }

    public function escanear(): void
    {
        $this->error = null;
        $codigo = trim($this->codigo);
        $producto = Producto::query()->where('activo', true)
            ->where(fn ($q) => $q->where('codigo_barras', $codigo)->orWhere('codigo', $codigo))->first();
        if ($producto === null) {
            $this->error = "No se encontró el producto «{$codigo}».";

            return;
        }
        $this->agregar($producto);
        $this->codigo = '';
    }

    /** Botones de acceso rápido de la caja. */
    public function agregarProducto(int $id): void
    {
        $this->error = null;
        $this->agregar(Producto::query()->where('activo', true)->findOrFail($id));
    }

    private function agregar(Producto $producto): void
    {
        $i = collect($this->carrito)->search(fn ($l) => $l['productoId'] === $producto->id);
        if ($i !== false) {
            $this->carrito[$i]['cantidad'] = Decimal::of($this->carrito[$i]['cantidad'])->add(1)->toPlainString();
        } else {
            $this->carrito[] = ['productoId' => $producto->id, 'descripcion' => $producto->descripcion, 'cantidad' => '1', 'precio' => $producto->precio, 'codigo' => $producto->codigo, 'tipoItem' => $producto->tipo_item, 'uniMedida' => $producto->uni_medida];
        }
    }

    public function quitar(int $i): void
    {
        unset($this->carrito[$i]);
        $this->carrito = array_values($this->carrito);
    }

    public function cobrar(EmisionDte $emision): void
    {
        Gate::authorize('emitir');
        $this->mensaje = $this->error = null;
        if ($this->carrito === []) {
            $this->error = 'El carrito está vacío.';

            return;
        }

        try {
            [$establecimiento, $puntoVenta] = $this->ubicacion();
            $dte = $emision->emitir($this->empresa(), $establecimiento, $puntoVenta, '01', $this->lineasDelCatalogo(), pagos: [new Pago(in_array($this->formaPago, ['01', '02', '03'], true) ? $this->formaPago : '01')]);
        } catch (DteException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $dte->refresh();
        $this->ultimoDteId = $dte->id;
        $this->carrito = [];
        $this->mensaje = match (true) {
            $dte->estado->tieneSello() => "Factura {$dte->numero_control} transmitida y sellada por Hacienda.",
            $dte->estado === EstadoDte::Transitorio => "Factura {$dte->numero_control} emitida en contingencia: ya puede entregarla; se transmitirá sola al volver Hacienda.",
            default => "Factura {$dte->numero_control} emitida. Se está enviando a Hacienda; el sello aparecerá en Documentos en unos segundos.",
        };
    }

    /**
     * Las líneas se arman con los datos del CATÁLOGO, nunca con lo que viene del navegador:
     * el carrito vive en el cliente y se podría manipular (precio, descripción, tipo).
     *
     * @return list<LineaSolicitud>
     */
    private function lineasDelCatalogo(): array
    {
        $lineas = [];
        foreach ($this->carrito as $l) {
            $cantidad = trim((string) ($l['cantidad'] ?? ''));
            if (! preg_match('/^\d{1,9}(\.\d{1,8})?$/', $cantidad) || Decimal::of($cantidad)->isZero()) {
                throw new DteException('Revise las cantidades: deben ser números mayores que cero.');
            }
            $p = Producto::query()->where('activo', true)->findOrFail((int) ($l['productoId'] ?? 0));
            $lineas[] = new LineaSolicitud($p->descripcion, $cantidad, (string) $p->precio, '0', 'gravada', $p->tipo_item, $p->uni_medida, $p->codigo);
        }

        return $lineas;
    }

    public function render(): View
    {
        $total = Decimal::zero();
        foreach ($this->carrito as $l) {
            $cantidad = preg_match('/^\d{1,9}(\.\d{1,8})?$/', (string) ($l['cantidad'] ?? '')) ? (string) $l['cantidad'] : '0';
            $precio = preg_match('/^\d{1,11}(\.\d{1,8})?$/', (string) ($l['precio'] ?? '')) ? (string) $l['precio'] : '0';
            $total = $total->add(Decimal::of($cantidad)->mul(Decimal::of($precio)->mul('1.13')->round(8)));
        }

        return view('livewire.panel.caja', [
            'total' => $total->round(2)->toFixed(2),
            'rapidos' => Producto::query()->where('activo', true)->orderBy('descripcion')->limit(12)->get(),
            'ultimo' => $this->ultimoDteId ? Dte::query()->find($this->ultimoDteId) : null,
        ]);
    }

    /** @return array{0: Establecimiento, 1: PuntoVenta} */
    private function ubicacion(): array
    {
        /** @var Usuario $usuario */
        $usuario = auth()->user();
        $puntoVenta = PuntoVenta::query()->findOrFail($usuario->puntoVentaActual() ?? PuntoVenta::query()->orderBy('id')->value('id'));

        return [Establecimiento::query()->findOrFail($puntoVenta->establecimiento_id), $puntoVenta];
    }

    private function empresa(): Empresa
    {
        return Empresa::query()->findOrFail(app(Tenancy::class)->empresaId());
    }
}
