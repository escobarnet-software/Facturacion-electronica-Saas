<?php

namespace App\Livewire\Panel;

use App\Dte\Emision\EmisionDte;
use App\Dte\Emision\LineaSolicitud;
use App\Dte\Excepciones\DteException;
use App\Dte\Modelos\Pago;
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

        $i = collect($this->carrito)->search(fn ($l) => $l['productoId'] === $producto->id);
        if ($i !== false) {
            $this->carrito[$i]['cantidad'] = Decimal::of($this->carrito[$i]['cantidad'])->add(1)->toPlainString();
        } else {
            $this->carrito[] = ['productoId' => $producto->id, 'descripcion' => $producto->descripcion, 'cantidad' => '1', 'precio' => $producto->precio, 'codigo' => $producto->codigo, 'tipoItem' => $producto->tipo_item, 'uniMedida' => $producto->uni_medida];
        }
        $this->codigo = '';
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
            $dte = $emision->emitir($this->empresa(), $establecimiento, $puntoVenta, '01', array_map(
                fn (array $l) => new LineaSolicitud($l['descripcion'], $l['cantidad'], $l['precio'], '0', 'gravada', $l['tipoItem'], $l['uniMedida'], $l['codigo']),
                $this->carrito,
            ), pagos: [new Pago($this->formaPago)]);
        } catch (DteException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $dte->refresh();
        $this->ultimoDteId = $dte->id;
        $this->carrito = [];
        $this->mensaje = $dte->estado->tieneSello()
            ? "Factura {$dte->numero_control} transmitida."
            : "Factura {$dte->numero_control} emitida: {$dte->estado->etiqueta()}.";
    }

    public function render(): View
    {
        $total = collect($this->carrito)->reduce(fn (Decimal $s, $l) => $s->add(Decimal::of($l['cantidad'])->mul(Decimal::of($l['precio'])->mul('1.13')->round(8))), Decimal::zero());

        return view('livewire.panel.caja', [
            'total' => $total->round(2)->toFixed(2),
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
