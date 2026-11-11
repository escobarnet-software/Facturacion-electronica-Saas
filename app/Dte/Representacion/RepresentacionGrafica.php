<?php

declare(strict_types=1);

namespace App\Dte\Representacion;

use App\Models\CatValor;
use App\Models\Dte;
use App\Models\Empresa;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Representación gráfica (PDF) de un DTE (Normativa regla 11.3; Anexo I regla 8; ESPECIFICACION §15).
 *
 *  - Un diseño único por tipo de documento para cada empresa (se permite su logo).
 *  - Campos con letra A siempre; B cuando traen valor; D con guion; C no se muestran.
 *    Se conservan los nombres de campo de la Normativa (config/dte/campos.php).
 *  - QR con la consulta pública, código de generación, número de control, sello y fecha/hora.
 *  - Ambiente 00: marca «SIN VALOR LEGAL — AMBIENTE DE PRUEBAS». Contingencia: se indica.
 *  - Formatos: carta y ticket de 80 mm.
 *
 * Motor: DomPDF (PHP puro, sin navegador en el servidor): suficiente para documentos tabulares,
 * soporta el tamaño de ticket y no agrega una dependencia de Chromium en producción.
 */
final class RepresentacionGrafica
{
    public const MARCA_PRUEBAS = 'SIN VALOR LEGAL — AMBIENTE DE PRUEBAS';

    /** Campos numéricos que NO son montos. */
    private const NO_MONETARIOS = ['numItem', 'cantidad', 'tipoItem', 'uniMedida', 'tipoDonacion', 'tipoGeneracion', 'porcentajeDescuento', 'porcentComision', 'cantidadDoc', 'condicionOperacion', 'codDomiciliado', 'tipoPersona', 'tipoItemExpor', 'modoTransp', 'codDocAsociado', 'periodo', 'version', 'tipoModelo', 'tipoOperacion', 'tipoContingencia'];

    public function html(Dte $dte, Empresa $empresa, string $formato = 'carta'): string
    {
        return view('dte.representacion', $this->datos($dte, $empresa, $formato))->render();
    }

    public function pdf(Dte $dte, Empresa $empresa, string $formato = 'carta'): string
    {
        $pdf = Pdf::loadHTML($this->html($dte, $empresa, $formato));
        $formato === 'ticket'
            ? $pdf->setPaper([0, 0, 226.77, 1400], 'portrait')   // 80 mm de ancho
            : $pdf->setPaper('letter', 'portrait');

        return $pdf->output();
    }

    /** @return array<string, mixed> */
    public function datos(Dte $dte, Empresa $empresa, string $formato = 'carta'): array
    {
        // Del texto exacto firmado: jsonb de PostgreSQL reordena las claves.
        $doc = json_decode($dte->json_texto, true, flags: JSON_THROW_ON_ERROR);
        $tipo = $dte->tipo;
        $ident = $doc['identificacion'] ?? [];

        return [
            'formato' => $formato,
            'titulo' => mb_strtoupper($this->catalogo('CAT-002', $tipo) ?? "Documento {$tipo}"),
            'version' => $dte->version,
            'pruebas' => $dte->ambiente === '00',
            'marcaPruebas' => self::MARCA_PRUEBAS,
            'contingencia' => (int) ($ident['tipoOperacion'] ?? 1) === 2,
            'logo' => $this->logo($empresa),
            'qr' => CodigoQr::dataUri($dte, $formato === 'ticket' ? 160 : 220),
            'urlConsulta' => CodigoQr::url($dte),
            'identificacion' => array_values(array_filter([
                ['Código de Generación', $dte->codigo_generacion],
                ['Número de Control', $dte->numero_control],
                ['Sello de Recepción', $dte->sello_recibido ?? 'Pendiente: documento emitido en contingencia'],
                ['Modelo de Facturación', $this->catalogo('CAT-003', (string) ($ident['tipoModelo'] ?? 1)) ?? (string) ($ident['tipoModelo'] ?? 1)],
                ['Tipo de Transmisión', $this->catalogo('CAT-004', (string) ($ident['tipoOperacion'] ?? 1)) ?? (string) ($ident['tipoOperacion'] ?? 1)],
                ['Fecha y Hora de Generación', $dte->fecha_emision->format('d/m/Y').' '.substr((string) $dte->hora_emision, 0, 8)],
                $dte->fh_procesamiento !== null ? ['Fecha y Hora de Procesamiento', $dte->fh_procesamiento->setTimezone('America/El_Salvador')->format('d/m/Y H:i:s')] : null,
            ])),
            'emisor' => $this->filas('emisor', $doc['emisor'] ?? [], $tipo),
            'receptor' => $this->filas('receptor', $doc['receptor'] ?? [], $tipo),
            // Solo las secciones que existen en la estructura del tipo (si no aplican: guion).
            'relacionados' => array_key_exists('documentoRelacionado', $doc) ? $this->tabla('documentoRelacionado', $doc['documentoRelacionado'], $tipo) : null,
            'asociados' => array_key_exists('otrosDocumentos', $doc) ? $this->tabla('otrosDocumentos', $doc['otrosDocumentos'], $tipo) : null,
            'ventaTercero' => isset($doc['ventaTercero']) && is_array($doc['ventaTercero']) ? $this->filas('ventaTercero', $doc['ventaTercero'], $tipo) : null,
            'cuerpo' => $this->tabla('cuerpoDocumento', $this->listaCuerpo($doc['cuerpoDocumento'] ?? []), $tipo),
            'resumen' => isset($doc['resumen']) ? $this->filasResumen($doc['resumen'], $tipo) : $this->filas('cuerpoDocumento', $this->resumenDcl($doc), $tipo),
            'extension' => isset($doc['extension']) && is_array($doc['extension']) ? $this->filas('extension', $doc['extension'], $tipo) : [],
            'totalLetras' => $doc['resumen']['totalLetras'] ?? $doc['cuerpoDocumento']['totalLetras'] ?? null,
            'observaciones' => $doc['resumen']['observaciones'] ?? null,
        ];
    }

    // ------------------------------------------------------------------

    public function letra(string $clave, string $tipo): string
    {
        $campo = $this->campo($clave);

        return is_array($campo) ? ($campo['por_tipo'][$tipo] ?? $campo['letra']) : 'C';
    }

    public function debeMostrarse(string $clave, string $tipo, mixed $valor): bool
    {
        return match ($this->letra($clave, $tipo)) {
            'A' => true,
            'B' => ! $this->vacio($valor),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return list<array{0: string, 1: string}>
     */
    private function filas(string $seccion, array $datos, string $tipo): array
    {
        $filas = [];
        foreach ($datos as $campo => $valor) {
            if (is_array($valor) && ! array_is_list($valor)) {
                // Municipio (CAT-013) y distrito (CAT-008) dependen del departamento.
                $departamento = isset($valor['departamento']) ? (string) $valor['departamento'] : null;
                foreach ($valor as $sub => $v) {
                    $clave = "{$seccion}.{$campo}.{$sub}";
                    if ($this->campo($clave) !== null && $this->debeMostrarse($clave, $tipo, $v)) {
                        $filas[] = [$this->etiqueta($clave), $this->formatear($clave, (string) $sub, $v, $sub === 'departamento' ? null : $departamento)];
                    }
                }

                continue;
            }
            $clave = "{$seccion}.{$campo}";
            if ($this->campo($clave) !== null && $this->debeMostrarse($clave, $tipo, $valor)) {
                $filas[] = [$this->etiqueta($clave), $this->formatear($clave, $campo, $valor)];
            }
        }

        return $filas;
    }

    /**
     * Tabla para secciones que son listas. null = la sección no aplica (se muestra con guion si es D).
     *
     * @param  list<array<string, mixed>>|null  $lista
     * @return array{columnas: list<string>, filas: list<list<string>>, guion: bool}|null
     */
    private function tabla(string $seccion, ?array $lista, string $tipo): ?array
    {
        if ($lista === null || $lista === []) {
            return ($this->campo($seccion)['guion'] ?? false) ? ['columnas' => [], 'filas' => [], 'guion' => true] : null;
        }

        $claves = [];
        foreach ($lista as $item) {
            foreach ($item as $campo => $valor) {
                $clave = "{$seccion}.{$campo}";
                if (! is_array($valor) || array_is_list($valor)) {
                    if ($this->campo($clave) !== null && $this->debeMostrarse($clave, $tipo, $valor)) {
                        $claves[$campo] = $clave;
                    }
                }
            }
        }

        $filas = [];
        foreach ($lista as $item) {
            $filas[] = array_values(array_map(fn (string $clave, string $campo) => $this->formatear($clave, $campo, $item[$campo] ?? null), $claves, array_keys($claves)));
        }

        return ['columnas' => array_values(array_map(fn ($c) => $this->etiqueta($c), $claves)), 'filas' => $filas, 'guion' => false];
    }

    /**
     * @param  array<string, mixed>  $resumen
     * @return list<array{0: string, 1: string}>
     */
    private function filasResumen(array $resumen, string $tipo): array
    {
        $filas = [];
        foreach ($resumen as $campo => $valor) {
            if ($campo === 'tributos') {
                foreach ((array) $valor as $t) {
                    if ($this->debeMostrarse('resumen.tributos.valor', $tipo, $t['valor'] ?? null) || $tipo === '11') {
                        $filas[] = [(string) ($t['descripcion'] ?? $t['codigo'] ?? 'Tributo'), $this->dinero($t['valor'] ?? 0)];
                    }
                }

                continue;
            }
            if (in_array($campo, ['pagos', 'totalLetras', 'observaciones'], true)) {
                continue;
            }
            $clave = "resumen.{$campo}";
            if ($this->campo($clave) !== null && $this->debeMostrarse($clave, $tipo, $valor)) {
                $filas[] = [$this->etiqueta($clave), $this->formatear($clave, $campo, $valor)];
            }
        }

        return $filas;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listaCuerpo(mixed $cuerpo): array
    {
        if (! is_array($cuerpo)) {
            return [];
        }

        return array_is_list($cuerpo) ? $cuerpo : []; // el DCL tiene el cuerpo como objeto: va al resumen
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function resumenDcl(array $doc): array
    {
        $cuerpo = $doc['cuerpoDocumento'] ?? [];

        return is_array($cuerpo) && ! array_is_list($cuerpo) ? $cuerpo : [];
    }

    /**
     * Las claves llevan puntos («emisor.nit»): se leen del arreglo, no con la notación de config().
     *
     * @return array<string, mixed>|null
     */
    private function campo(string $clave): ?array
    {
        $campos = (array) config('dte.campos', []);

        return isset($campos[$clave]) && is_array($campos[$clave]) ? $campos[$clave] : null;
    }

    private function etiqueta(string $clave): string
    {
        return (string) ($this->campo($clave)['etiqueta'] ?? $clave);
    }

    private function formatear(string $clave, string $campo, mixed $valor, ?string $padre = null): string
    {
        if ($valor === null || $valor === '' || $valor === []) {
            return '-';
        }
        if (is_array($valor)) {
            return implode(', ', array_map(fn ($v) => (string) $v, $valor));
        }
        if ($catalogo = $this->campo($clave)['catalogo'] ?? null) {
            $descripcion = $this->catalogo((string) $catalogo, (string) $valor, $padre);

            return $descripcion !== null ? "{$valor} - {$descripcion}" : (string) $valor;
        }
        if ((is_int($valor) || is_float($valor)) && ! in_array($campo, self::NO_MONETARIOS, true)) {
            return $this->dinero($valor);
        }
        if (is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return substr($valor, 8, 2).'/'.substr($valor, 5, 2).'/'.substr($valor, 0, 4);
        }
        if (is_bool($valor)) {
            return $valor ? 'Sí' : 'No';
        }

        return (string) $valor;
    }

    private function dinero(mixed $valor): string
    {
        $texto = is_float($valor) ? (string) json_encode($valor) : (string) $valor;
        $negativo = str_starts_with($texto, '-');
        $texto = ltrim($texto, '-');
        [$entero, $decimales] = array_pad(explode('.', $texto, 2), 2, '');
        $decimales = str_pad($decimales, 2, '0');

        return ($negativo ? '-' : '').'$'.number_format((int) $entero).'.'.$decimales;
    }

    private function vacio(mixed $valor): bool
    {
        return $valor === null || $valor === '' || $valor === [] || ((is_int($valor) || is_float($valor)) && (float) $valor === 0.0);
    }

    private function catalogo(string $catalogo, string $codigo, ?string $padre = null): ?string
    {
        $consulta = CatValor::query()->where('catalogo', $catalogo)->where('codigo', $codigo)->where('vigente', true);
        if ($padre !== null && in_array($catalogo, ['CAT-008', 'CAT-013'], true)) {
            $consulta->where('padre', $padre);
        }
        $valor = $consulta->value('valor');

        return is_string($valor) ? trim($valor) : null;
    }

    private function logo(Empresa $empresa): ?string
    {
        $ruta = $empresa->getAttribute('logo_path');
        if (! is_string($ruta) || ! Storage::disk((string) config('dte.archivo.disco'))->exists($ruta)) {
            return null;
        }
        $contenido = (string) Storage::disk((string) config('dte.archivo.disco'))->get($ruta);

        return 'data:'.(new \finfo(FILEINFO_MIME_TYPE))->buffer($contenido).';base64,'.base64_encode($contenido);
    }
}
