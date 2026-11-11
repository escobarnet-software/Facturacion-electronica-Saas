<?php

namespace App\Livewire\Panel;

use App\Dte\Emision\EmisionDte;
use App\Dte\Emision\LineaSolicitud;
use App\Dte\Excepciones\DteException;
use App\Dte\Modelos\Pago;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\Usuario;
use App\Tenancy\Tenancy;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Emitir Factura (01) o Crédito Fiscal (03): cliente del catálogo, productos del catálogo
 * o líneas libres, resumen en vivo y emisión. La plataforma calcula todos los totales.
 */
#[Layout('layouts.panel')]
#[Title('Emitir')]
class Emitir extends Component
{
    public string $tipo = '01';

    public ?int $clienteId = null;

    public ?string $correo = null;

    public ?int $puntoVentaId = null;

    public int $condicionOperacion = 1;

    public string $formaPago = '01';

    public ?string $observaciones = null;

    /** @var list<array{descripcion: string, cantidad: string, precio: string, descuento: string, tipoVenta: string, codigo: string|null, tipoItem: int, uniMedida: int}> */
    public array $lineas = [];

    public ?string $error = null;

    public ?string $emitido = null;

    public function mount(): void
    {
        Gate::authorize('emitir');
        /** @var Usuario $usuario */
        $usuario = auth()->user();
        $this->puntoVentaId = $usuario->puntoVentaActual() ?? PuntoVenta::query()->orderBy('id')->value('id');
        $this->agregarLinea();
    }

    public function agregarLinea(): void
    {
        $this->lineas[] = ['descripcion' => '', 'cantidad' => '1', 'precio' => '0', 'descuento' => '0', 'tipoVenta' => 'gravada', 'codigo' => null, 'tipoItem' => 1, 'uniMedida' => 59];
    }

    public function agregarProducto(int $productoId): void
    {
        $p = Producto::query()->findOrFail($productoId);
        $vacia = collect($this->lineas)->search(fn ($l) => trim($l['descripcion']) === '');
        $linea = ['descripcion' => $p->descripcion, 'cantidad' => '1', 'precio' => $p->precio, 'descuento' => '0', 'tipoVenta' => $p->tipo_venta, 'codigo' => $p->codigo, 'tipoItem' => $p->tipo_item, 'uniMedida' => $p->uni_medida];
        $vacia === false ? $this->lineas[] = $linea : $this->lineas[$vacia] = $linea;
    }

    public function quitarLinea(int $i): void
    {
        unset($this->lineas[$i]);
        $this->lineas = array_values($this->lineas);
    }

    /** @return array<string, mixed>|null */
    public function resumen(): ?array
    {
        try {
            [$establecimiento, $puntoVenta] = $this->ubicacion();

            return app(EmisionDte::class)->previsualizar($this->empresa(), $establecimiento, $puntoVenta, $this->tipo, $this->solicitud(), $this->cliente());
        } catch (\Throwable) {
            return null; // datos incompletos: aún no hay resumen
        }
    }

    public function emitir(EmisionDte $emision): void
    {
        Gate::authorize('emitir');
        $this->error = $this->emitido = null;
        $this->validate([
            'tipo' => 'required|in:01,03',
            'lineas' => 'required|array|min:1',
            'lineas.*.descripcion' => 'required|string|max:1000',
            'lineas.*.cantidad' => ['required', 'regex:/^\d+(\.\d{1,8})?$/'],
            'lineas.*.precio' => ['required', 'regex:/^\d+(\.\d{1,8})?$/'],
            'lineas.*.descuento' => ['nullable', 'regex:/^\d+(\.\d{1,8})?$/'],
            'correo' => 'nullable|email',
        ]);

        try {
            [$establecimiento, $puntoVenta] = $this->ubicacion();
            $dte = $emision->emitir(
                $this->empresa(), $establecimiento, $puntoVenta, $this->tipo, $this->solicitud(), $this->cliente(),
                [new Pago($this->formaPago)], $this->condicionOperacion, $this->observaciones, $this->correo,
            );
        } catch (DteException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->emitido = $dte->numero_control;
        $this->lineas = [];
        $this->agregarLinea();
        $this->dispatch('dte-emitido', id: $dte->id);
    }

    public function render(): View
    {
        return view('livewire.panel.emitir', [
            'clientes' => Cliente::query()->orderBy('nombre')->get(['id', 'nombre', 'nrc']),
            'productos' => Producto::query()->where('activo', true)->orderBy('descripcion')->limit(200)->get(),
            'puntosVenta' => PuntoVenta::query()->with('establecimiento')->orderBy('id')->get(),
            'vistaPrevia' => $this->resumen(),
            'ultimos' => Dte::query()->latest('id')->limit(5)->get(['id', 'numero_control', 'estado', 'total_pagar']),
        ]);
    }

    /** @return list<LineaSolicitud> */
    private function solicitud(): array
    {
        return array_values(array_map(fn (array $l) => new LineaSolicitud(
            trim($l['descripcion']), (string) $l['cantidad'], (string) $l['precio'], (string) ($l['descuento'] ?: '0'),
            $l['tipoVenta'], (int) $l['tipoItem'], (int) $l['uniMedida'], $l['codigo'] ?: null,
        ), $this->lineas));
    }

    private function cliente(): ?Cliente
    {
        return $this->clienteId ? Cliente::query()->findOrFail($this->clienteId) : null;
    }

    /** @return array{0: Establecimiento, 1: PuntoVenta} */
    private function ubicacion(): array
    {
        /** @var Usuario $usuario */
        $usuario = auth()->user();
        // El cajero solo emite desde su punto de venta.
        $id = $usuario->puntoVentaActual() ?? $this->puntoVentaId;
        $puntoVenta = PuntoVenta::query()->findOrFail($id);

        return [Establecimiento::query()->findOrFail($puntoVenta->establecimiento_id), $puntoVenta];
    }

    private function empresa(): Empresa
    {
        return Empresa::query()->findOrFail(app(Tenancy::class)->empresaId());
    }
}
