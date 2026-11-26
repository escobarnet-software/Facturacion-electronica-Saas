<?php

namespace App\Livewire\Panel;

use App\Dte\Emision\EmisionDte;
use App\Dte\Emision\LineaSolicitud;
use App\Dte\Excepciones\DteException;
use App\Dte\Validacion\ReglasHacienda as R;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Tenancy\Tenancy;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Nota de Crédito (05) o de Débito (06) sobre un Crédito Fiscal con sello. Las líneas se precargan
 * desde el Crédito Fiscal: se ajustan las cantidades o los precios de lo que se devuelve, descuenta o
 * se cobra de más. Solo dueño y contador (afecta el IVA declarado).
 */
#[Layout('layouts.panel')]
#[Title('Nota de crédito o débito')]
class NotaAjuste extends Component
{
    #[Locked]
    public int $dteId;

    #[Locked]
    public string $tipo = '05';

    /** @var list<array{descripcion: string, cantidad: string, precio: string, descuento: string, tipoVenta: string, codigo: string|null, tipoItem: int, uniMedida: int}> */
    public array $lineas = [];

    public string $motivo = '';

    public ?string $error = null;

    public ?string $emitido = null;

    public function mount(int $dte, ?string $tipo = null): void
    {
        Gate::authorize('invalidar');
        $ccf = Dte::query()->findOrFail($dte); // EmpresaScope + RLS: otra empresa → 404
        abort_unless($ccf->tipo === '03', 404);
        $this->dteId = $ccf->id;
        $this->tipo = request()->query('tipo') === '06' || $tipo === '06' ? '06' : '05';
        $texto = fn ($v) => is_float($v) ? (string) json_encode($v) : (string) $v;
        foreach ((array) ($ccf->json_original['cuerpoDocumento'] ?? []) as $item) {
            $venta = match (true) {
                (float) ($item['ventaExenta'] ?? 0) > 0 => 'exenta',
                (float) ($item['ventaNoSuj'] ?? 0) > 0 => 'no_sujeta',
                default => 'gravada',
            };
            $this->lineas[] = [
                'descripcion' => (string) $item['descripcion'], 'cantidad' => $texto($item['cantidad']), 'precio' => $texto($item['precioUni']),
                'descuento' => $texto($item['montoDescu'] ?? 0), 'tipoVenta' => $venta, 'codigo' => $item['codigo'] ?? null,
                'tipoItem' => (int) ($item['tipoItem'] ?? 1), 'uniMedida' => (int) ($item['uniMedida'] ?? 59),
            ];
        }
    }

    public function quitarLinea(int $i): void
    {
        unset($this->lineas[$i]);
        $this->lineas = array_values($this->lineas);
    }

    /** @return array<string, mixed> */
    protected function reglas(): array
    {
        return [
            'lineas' => 'required|array|min:1|max:2000',
            'lineas.*.descripcion' => R::texto('descripcion'),
            'lineas.*.cantidad' => R::cantidad(),
            'lineas.*.precio' => R::monto(),
            'lineas.*.descuento' => R::monto(false),
            'lineas.*.tipoVenta' => 'required|in:gravada,exenta,no_sujeta',
            'motivo' => 'required|string|min:5|max:3000',
        ];
    }

    public function updated(string $propiedad): void
    {
        if (preg_match('/^lineas\.\d+\.(descripcion|cantidad|precio|descuento)$/', $propiedad) || $propiedad === 'motivo') {
            $regla = (string) preg_replace('/^lineas\.\d+\./', 'lineas.*.', $propiedad);
            $this->validateOnly($propiedad, [$propiedad => $this->reglas()[$regla]], [
                ...R::mensajes([$propiedad => str_ends_with($propiedad, 'cantidad') ? 'cantidad' : 'monto']),
            ], ['motivo' => 'motivo']);
        }
    }

    public function emitir(EmisionDte $emision): void
    {
        Gate::authorize('invalidar');
        $this->error = $this->emitido = null;
        $this->validate($this->reglas(), [
            ...R::mensajes(['lineas.*.cantidad' => 'cantidad', 'lineas.*.precio' => 'monto', 'lineas.*.descuento' => 'monto']),
            'motivo.required' => 'Escriba el motivo: aparece en el documento (devolución, descuento, error en el precio…).',
            'lineas.required' => 'Deje al menos una línea.',
        ], ['motivo' => 'motivo', 'lineas.*.descripcion' => 'descripción']);

        try {
            $ccf = Dte::query()->findOrFail($this->dteId);
            $pv = PuntoVenta::query()->findOrFail($ccf->punto_venta_id);
            $nota = $emision->emitir($this->empresa(), Establecimiento::query()->findOrFail($pv->establecimiento_id), $pv, $this->tipo, $this->solicitud(),
                observaciones: $this->motivo, relacionado: $ccf);
        } catch (DteException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $this->emitido = $nota->numero_control;
    }

    public function render(EmisionDte $emision): View
    {
        $ccf = Dte::query()->findOrFail($this->dteId);
        $resumen = null;
        try {
            if ($this->lineas !== []) {
                $pv = PuntoVenta::query()->findOrFail($ccf->punto_venta_id);
                $resumen = $emision->previsualizar($this->empresa(), Establecimiento::query()->findOrFail($pv->establecimiento_id), $pv, $this->tipo, $this->solicitud(), relacionado: $ccf);
            }
        } catch (Throwable) {
            $resumen = null; // datos incompletos o inválidos: el error se muestra al emitir
        }

        return view('livewire.panel.nota-ajuste', ['ccf' => $ccf, 'resumen' => $resumen]);
    }

    /** @return list<LineaSolicitud> */
    private function solicitud(): array
    {
        return array_values(array_map(fn (array $l) => new LineaSolicitud(
            (string) $l['descripcion'], (string) $l['cantidad'], (string) $l['precio'], (string) ($l['descuento'] ?: '0'),
            (string) $l['tipoVenta'], (int) $l['tipoItem'], (int) $l['uniMedida'], $l['codigo'] ?? null,
        ), $this->lineas));
    }

    private function empresa(): Empresa
    {
        return Empresa::query()->findOrFail(app(Tenancy::class)->empresaId());
    }
}
