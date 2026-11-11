<?php

declare(strict_types=1);

namespace App\Dte\Correlativos;

use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Tenancy\Tenancy;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Asigna correlativos sin repetir: SELECT … FOR UPDATE dentro de una transacción.
 *
 * - Se llama FUERA de la transacción de la emisión: así el número queda consumido
 *   (confirmado) aunque el documento falle después. Esos números consumidos se
 *   registran como anulados internamente (Paso 4); nunca se vuelven a entregar.
 * - El año sale de la fecha de emisión en hora de El Salvador: el 1 de enero
 *   empieza una fila nueva, es decir, el correlativo se reinicia en 1.
 * - Un número entregado nunca se reutiliza, aunque el documento falle después.
 */
final class Correlativos
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function siguiente(
        Empresa $empresa,
        string $tipoDte,
        Establecimiento $establecimiento,
        PuntoVenta $puntoVenta,
        DateTimeInterface $fechaEmision,
    ): int {
        if ($this->tenancy->empresaId() !== $empresa->id) {
            throw new LogicException('El correlativo solo se asigna para la empresa activa.');
        }
        if ($establecimiento->empresa_id !== $empresa->id || $puntoVenta->establecimiento_id !== $establecimiento->id) {
            throw new LogicException('El establecimiento o el punto de venta no pertenecen a la empresa.');
        }

        $clave = $this->clave(ModoCorrelativo::from($empresa->modo_correlativo), $tipoDte, $establecimiento, $puntoVenta);
        $anio = (int) DateTimeImmutable::createFromInterface($fechaEmision)
            ->setTimezone(new DateTimeZone((string) config('dte.zona_horaria')))
            ->format('Y');

        return DB::transaction(function () use ($empresa, $clave, $anio): int {
            DB::table('correlativos')->insertOrIgnore([
                'empresa_id' => $empresa->id, 'clave' => $clave, 'anio' => $anio, 'ultimo' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $fila = DB::selectOne(
                'SELECT id, ultimo FROM correlativos WHERE empresa_id = ? AND clave = ? AND anio = ? FOR UPDATE',
                [$empresa->id, $clave, $anio],
            );

            $siguiente = (int) $fila->ultimo + 1;
            DB::update('UPDATE correlativos SET ultimo = ?, updated_at = now() WHERE id = ?', [$siguiente, $fila->id]);

            return $siguiente;
        });
    }

    public function clave(ModoCorrelativo $modo, string $tipoDte, Establecimiento $establecimiento, PuntoVenta $puntoVenta): string
    {
        return match ($modo) {
            ModoCorrelativo::PorTipo => "tipo:{$tipoDte}|est:{$establecimiento->id}|pv:{$puntoVenta->id}",
            ModoCorrelativo::PorEstablecimiento => "est:{$establecimiento->id}",
            ModoCorrelativo::Centralizado => 'empresa',
        };
    }
}
