<?php

declare(strict_types=1);

namespace App\Plataforma;

use App\Dte\Emision\EmisionDte;
use App\Dte\Emision\LineaSolicitud;
use App\Dte\Excepciones\DteException;
use App\Models\Bitacora;
use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\ConsumoMensual;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Plan;
use App\Models\PuntoVenta;
use App\Models\Suscripcion;
use App\Support\Decimal;
use App\Tenancy\Tenancy;

/**
 * Cobro mensual de las suscripciones: plan + documentos excedentes × precio adicional.
 * Escobar Net factura a sus clientes con su propia plataforma (CCF si el cliente es contribuyente).
 * Idempotente: un cobro por empresa y período.
 */
final class FacturadorSuscripciones
{
    public function __construct(private readonly Tenancy $tenancy, private readonly EmisionDte $emision) {}

    /** @return list<Cobro> */
    public function facturar(string $periodo, bool $emitirDte = true): array
    {
        // Enumerar las empresas con suscripción activa es una tarea de plataforma (sin aislamiento, solo ids).
        $empresas = $this->tenancy->sinAislamiento(fn () => Suscripcion::query()->where('estado', 'activa')->distinct()->pluck('empresa_id')->all());
        $plataforma = config('plataforma.empresa_id');
        $cobros = [];

        foreach ($empresas as $empresaId) {
            if ((int) $empresaId === $plataforma) {
                continue;
            }
            $cobro = $this->tenancy->ejecutarComo((int) $empresaId, fn () => $this->calcular($periodo));
            if ($cobro === null) {
                continue;
            }
            if ($emitirDte && $plataforma !== null && $cobro->dte_codigo_generacion === null) {
                $codigo = $this->emitirDte($cobro, (int) $empresaId, (int) $plataforma);
                $this->tenancy->ejecutarComo((int) $empresaId, fn () => $cobro->forceFill(['dte_codigo_generacion' => $codigo])->save());
            }
            $cobros[] = $cobro;
        }

        return $cobros;
    }

    /** Calcula (o devuelve) el cobro del período para la empresa activa. */
    public function calcular(string $periodo): ?Cobro
    {
        $existente = Cobro::query()->where('periodo', $periodo)->first();
        if ($existente !== null) {
            return $existente;
        }
        $plan = Consumo::plan();
        if ($plan === null) {
            return null;
        }
        $documentos = (int) (ConsumoMensual::query()->where('periodo', $periodo)->value('documentos') ?? 0);
        $excedente = $plan->documentos_mes === null ? 0 : max(0, $documentos - $plan->documentos_mes);
        $cargoPlan = Decimal::of((string) $plan->getRawOriginal('precio_mensual'));
        $cargoExcedente = Decimal::of((string) $plan->getRawOriginal('precio_documento_adicional'))->mul($excedente)->round(2);

        $cobro = Cobro::query()->create([
            'periodo' => $periodo, 'plan_id' => $plan->id,
            'cargo_plan' => $cargoPlan->toFixed(2), 'documentos' => $documentos, 'excedente' => $excedente,
            'cargo_excedente' => $cargoExcedente->toFixed(2), 'subtotal' => $cargoPlan->add($cargoExcedente)->toFixed(2),
        ]);
        Bitacora::registrar('cobro.generado', $cobro, ['periodo' => $periodo, 'documentos' => $documentos, 'excedente' => $excedente]);

        return $cobro;
    }

    /** Emite el DTE de cobro desde la empresa de Escobar Net hacia el cliente. Devuelve el código de generación. */
    private function emitirDte(Cobro $cobro, int $clienteEmpresaId, int $plataformaId): string
    {
        $cliente = $this->tenancy->sinAislamiento(fn () => Empresa::query()->findOrFail($clienteEmpresaId));
        $planNombre = $this->tenancy->sinAislamiento(fn () => (string) Plan::query()->whereKey($cobro->plan_id)->value('nombre'));

        return $this->tenancy->ejecutarComo($plataformaId, function () use ($cobro, $cliente, $planNombre, $plataformaId) {
            $escobar = Empresa::query()->findOrFail($plataformaId);
            $establecimiento = Establecimiento::query()->orderBy('id')->first() ?? throw new DteException('La empresa de la plataforma no tiene establecimiento.');
            $puntoVenta = PuntoVenta::query()->where('establecimiento_id', $establecimiento->id)->orderBy('id')->firstOrFail();

            $receptor = Cliente::query()->firstOrNew(['tipo_documento' => '36', 'num_documento' => $cliente->nit]);
            $receptor->fill([
                'nombre' => $cliente->razon_social, 'nrc' => $cliente->nrc, 'nombre_comercial' => $cliente->nombre_comercial,
                'cod_actividad' => $cliente->getAttribute('cod_actividad'), 'desc_actividad' => $cliente->getAttribute('desc_actividad'),
                'direccion' => $cliente->getAttribute('direccion'), 'telefono' => $cliente->getAttribute('telefono'), 'correo' => $cliente->getAttribute('correo'),
            ])->save();

            $lineas = [new LineaSolicitud("Suscripción plan {$planNombre} — período {$cobro->periodo}", '1', (string) $cobro->getRawOriginal('cargo_plan'), tipoItem: 2, uniMedida: 99)];
            if ($cobro->excedente > 0) {
                $unitario = Decimal::of((string) $cobro->getRawOriginal('cargo_excedente'))->div($cobro->excedente, 8);
                $lineas[] = new LineaSolicitud("Documentos adicionales al plan ({$cobro->periodo})", (string) $cobro->excedente, (string) $unitario, tipoItem: 2, uniMedida: 99);
            }
            $tipo = $receptor->puedeRecibirCcf() ? '03' : '01';
            $dte = $this->emision->emitir($escobar, $establecimiento, $puntoVenta, $tipo, $lineas, $receptor, correoReceptor: $receptor->correo);

            return $dte->codigo_generacion;
        });
    }
}
