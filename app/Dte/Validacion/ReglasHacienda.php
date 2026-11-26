<?php

declare(strict_types=1);

namespace App\Dte\Validacion;

use App\Models\CatValor;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Reglas de los datos que exige Hacienda, en un solo lugar, para todos los formularios y la API.
 *
 * Fuentes (en orden de prioridad del CLAUDE.md):
 *  - Esquemas JSON oficiales (resources/schemas): longitudes mínimas y máximas, rangos, decimales
 *    (multipleOf 0.00000001), obligatorios, y el patrón del número de control
 *    (DTE-xx-(M|B|S|P)ddd(P)ddd-…) del que salen los códigos de establecimiento y punto de venta.
 *  - Catálogos oficiales (cat_valores): actividades (CAT-019), departamentos (CAT-012),
 *    municipios (CAT-013), distritos (CAT-008), tipos de documento (CAT-022), unidades (CAT-014),
 *    tipos de ítem (CAT-011).
 *  - Convención de Hacienda para identificadores: NIT de 14 dígitos o DUI homologado de 9 dígitos,
 *    sin guiones; DUI con el formato 00000000-0.
 *
 * Las longitudes están verificadas contra los esquemas en tests/Feature/Validacion.
 */
final class ReglasHacienda
{
    /** Límites tomados de los esquemas oficiales (fe-ccf-v4, fe-f-v2 y demás). */
    public const LIMITES = [
        'nombre' => [1, 250],
        'nombre_comercial' => [1, 150],
        'nrc' => [2, 8],
        'cod_actividad' => [5, 6],
        'desc_actividad' => [5, 150],
        'complemento' => [1, 200],
        'telefono' => [8, 30],
        'correo' => [6, 100],
        'num_documento' => [1, 20],
        'descripcion' => [1, 1500],
        'codigo' => [1, 25],
        'observaciones' => [1, 3000],
    ];

    /** Mayor monto o cantidad que admiten los esquemas (exclusiveMaximum 100000000000). */
    public const MAXIMO = '99999999999.99999999';

    // ------------------------------------------------------------------ Normalización

    /** NIT y NRC: solo dígitos (se aceptan guiones y espacios al escribir y se quitan). */
    public static function soloDigitos(?string $valor): ?string
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }

        return preg_replace('/[\s-]/', '', $valor);
    }

    /** DUI: se guarda con el formato 00000000-0. */
    public static function dui(?string $valor): ?string
    {
        $d = self::soloDigitos($valor);

        return $d !== null && preg_match('/^\d{9}$/', $d) ? substr($d, 0, 8).'-'.substr($d, 8) : $d;
    }

    /** Normaliza el número de documento según su tipo (CAT-022). */
    public static function documento(?string $tipo, ?string $valor): ?string
    {
        return match ($tipo) {
            '36' => self::soloDigitos($valor),
            '13' => self::dui($valor),
            default => $valor === null ? null : trim($valor),
        };
    }

    // ------------------------------------------------------------------ Identificadores

    /** @return list<mixed> */
    public static function nit(bool $requerido = true): array
    {
        return [$requerido ? 'required' : 'nullable', 'regex:/^(\d{9}|\d{14})$/'];
    }

    /** @return list<mixed> */
    public static function nrc(bool $requerido = true): array
    {
        return [$requerido ? 'required' : 'nullable', 'regex:/^\d{2,8}$/'];
    }

    /**
     * Número de documento según el tipo elegido (CAT-022).
     *
     * @return list<mixed>
     */
    public static function numeroDocumento(?string $tipo, bool $requerido = true): array
    {
        $base = [$requerido ? 'required' : 'nullable', 'string'];

        return match ($tipo) {
            '36' => [...$base, 'regex:/^(\d{9}|\d{14})$/'],
            '13' => [...$base, 'regex:/^\d{8}-\d$/'],
            default => [...$base, 'min:1', 'max:20'],
        };
    }

    /** @return list<mixed> */
    public static function tipoDocumento(bool $requerido = true): array
    {
        return [$requerido ? 'required' : 'nullable', self::catalogo('CAT-022')];
    }

    // ------------------------------------------------------------------ Datos generales

    /** @return list<mixed> */
    public static function texto(string $campo, bool $requerido = true): array
    {
        [$min, $max] = self::LIMITES[$campo];

        return [$requerido ? 'required' : 'nullable', 'string', "min:{$min}", "max:{$max}"];
    }

    /** @return list<mixed> */
    public static function correo(bool $requerido = true): array
    {
        return [$requerido ? 'required' : 'nullable', 'string', 'email:rfc', 'min:6', 'max:100'];
    }

    /** @return list<mixed> */
    public static function telefono(bool $requerido = true): array
    {
        return [$requerido ? 'required' : 'nullable', 'string', 'min:8', 'max:30', 'regex:/^[0-9+()\s-]+$/'];
    }

    /** @return list<mixed> */
    public static function actividad(bool $requerido = true): array
    {
        return [$requerido ? 'required' : 'nullable', 'regex:/^\d{5,6}$/', self::catalogo('CAT-019')];
    }

    /** @return list<mixed> */
    public static function departamento(bool $requerido = true): array
    {
        return [$requerido ? 'required' : 'nullable', self::catalogo('CAT-012')];
    }

    /**
     * Municipio del departamento elegido (CAT-013).
     *
     * @return list<mixed>
     */
    public static function municipio(?string $departamento, bool $requerido = true): array
    {
        return [$requerido ? 'required' : 'nullable', self::catalogo('CAT-013', $departamento)];
    }

    /**
     * Distrito del departamento elegido (CAT-008).
     *
     * @return list<mixed>
     */
    public static function distrito(?string $departamento, bool $requerido = true): array
    {
        return [$requerido ? 'required' : 'nullable', self::catalogo('CAT-008', $departamento)];
    }

    /**
     * Código de establecimiento de Hacienda: M, B, S o P + 3 dígitos (patrón del número de control).
     *
     * @return list<mixed>
     */
    public static function codigoEstablecimiento(): array
    {
        return ['required', 'regex:/^[MBSP]\d{3}$/'];
    }

    /**
     * Código de punto de venta de Hacienda: P + 3 dígitos (patrón del número de control).
     *
     * @return list<mixed>
     */
    public static function codigoPuntoVenta(): array
    {
        return ['required', 'regex:/^P\d{3}$/'];
    }

    // ------------------------------------------------------------------ Detalle (ítems)

    /**
     * Cantidad: mayor que cero, hasta 8 decimales, menor que 100 000 000 000.
     *
     * @return list<mixed>
     */
    public static function cantidad(): array
    {
        return ['required', 'regex:/^\d{1,11}(\.\d{1,8})?$/', self::mayorQueCero()];
    }

    /**
     * Precio o descuento: cero o más, hasta 8 decimales.
     *
     * @return list<mixed>
     */
    public static function monto(bool $requerido = true): array
    {
        return [$requerido ? 'required' : 'nullable', 'regex:/^\d{1,11}(\.\d{1,8})?$/'];
    }

    /** @return list<mixed> */
    public static function unidadMedida(): array
    {
        return ['required', 'integer', self::catalogo('CAT-014')];
    }

    /** @return list<mixed> */
    public static function tipoItem(): array
    {
        return ['required', 'integer', self::catalogo('CAT-011')];
    }

    // ------------------------------------------------------------------ Mensajes

    /**
     * Mensajes claros, en español, con el formato que pide Hacienda.
     *
     * @param  array<string, string>  $campos  ruta del campo → tipo de regla (nit, nrc, documento, actividad…)
     * @return array<string, string>
     */
    public static function mensajes(array $campos): array
    {
        $textos = [
            'nit' => ['regex' => 'El NIT lleva 14 dígitos (o 9 si es DUI homologado), sin guiones.'],
            'nrc' => ['regex' => 'El NRC lleva de 2 a 8 dígitos, sin guiones.'],
            'documento' => ['regex' => 'Formato inválido: NIT con 9 o 14 dígitos; DUI como 00000000-0.'],
            'actividad' => ['regex' => 'El código de actividad tiene 5 o 6 dígitos.', 'exists' => 'Elija una actividad del catálogo oficial de Hacienda (CAT-019).'],
            'departamento' => ['exists' => 'Elija un departamento del catálogo oficial (CAT-012).'],
            'municipio' => ['exists' => 'Elija un municipio que pertenezca al departamento (CAT-013).'],
            'distrito' => ['exists' => 'Elija un distrito que pertenezca al departamento (CAT-008).'],
            'telefono' => ['regex' => 'El teléfono solo puede tener números, espacios, guiones, paréntesis y +.', 'min' => 'El teléfono debe tener al menos 8 caracteres.'],
            'correo' => ['email' => 'Escriba un correo válido, por ejemplo cliente@empresa.com.', 'min' => 'El correo debe tener al menos 6 caracteres.'],
            'cantidad' => ['regex' => 'La cantidad es un número positivo con hasta 8 decimales.'],
            'monto' => ['regex' => 'Escriba un monto con hasta 8 decimales, sin comas ni signo de dólar (por ejemplo 12.50).'],
            'establecimiento' => ['regex' => 'El código de Hacienda es M, B, S o P seguido de 3 dígitos (por ejemplo M001).'],
            'puntoVenta' => ['regex' => 'El código del punto de venta es P seguido de 3 dígitos (por ejemplo P001).'],
            'unidad' => ['exists' => 'Elija una unidad de medida del catálogo oficial (CAT-014).'],
            'tipoDocumento' => ['exists' => 'Elija un tipo de documento del catálogo oficial (CAT-022).'],
        ];
        $mensajes = [];
        foreach ($campos as $campo => $tipo) {
            foreach ($textos[$tipo] ?? [] as $regla => $texto) {
                $mensajes["{$campo}.{$regla}"] = $texto;
            }
        }

        return $mensajes;
    }

    // ------------------------------------------------------------------ Catálogos para formularios

    /** @return array<string, string> código → nombre */
    public static function opciones(string $catalogo, ?string $padre = null): array
    {
        return CatValor::query()->where('catalogo', $catalogo)->where('vigente', true)
            ->when($padre !== null, fn ($q) => $q->where('padre', $padre))
            ->orderBy('codigo')->pluck('valor', 'codigo')->all();
    }

    public static function nombreActividad(?string $codigo): ?string
    {
        return $codigo ? CatValor::query()->where(['catalogo' => 'CAT-019', 'codigo' => $codigo])->value('valor') : null;
    }

    private static function catalogo(string $catalogo, ?string $padre = null): Exists
    {
        $regla = Rule::exists('cat_valores', 'codigo')->where('catalogo', $catalogo)->where('vigente', true);

        return $padre === null ? $regla : $regla->where('padre', $padre);
    }

    private static function mayorQueCero(): Closure
    {
        return function (string $atributo, mixed $valor, Closure $falla): void {
            if (is_string($valor) && preg_match('/^\d+(\.\d+)?$/', $valor) && ! preg_match('/[1-9]/', $valor)) {
                $falla('La cantidad debe ser mayor que cero.');
            }
        };
    }
}
