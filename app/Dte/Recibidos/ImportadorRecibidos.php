<?php

declare(strict_types=1);

namespace App\Dte\Recibidos;

use App\Dte\Excepciones\DteException;
use App\Dte\Representacion\CodigoQr;
use App\Dte\Validacion\ValidadorEsquema;
use App\Models\Bitacora;
use App\Models\Dte;
use App\Models\DteRecibido;
use App\Models\Empresa;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

/**
 * Importa los DTE que la empresa recibe de sus proveedores (subidos o por el buzón de correo).
 *
 *  1. Debe ser un Archivo DTE completo: estructura + firmaElectronica (JWS) + selloRecibido (40 caracteres).
 *  2. Debe estar dirigido a esta empresa (NIT del receptor).
 *  3. El contenido debe coincidir con lo firmado: el payload del JWS es exactamente el documento.
 *     Si alguien alteró un monto después de firmar, no coincide y se rechaza.
 *  4. Debe cumplir el esquema oficial de su tipo y versión.
 *  5. Opcional: verificación en la consulta pública de Hacienda (desactivada por defecto).
 *
 * TODO(normativa): verificar la firma RSA del JWS requiere el certificado público del emisor, que
 * Hacienda no publica; por ahora se verifica la integridad del contenido frente al payload firmado.
 */
final class ImportadorRecibidos
{
    /** Tipos que generan compras o retenciones para el receptor. */
    public const TIPOS = ['01', '03', '05', '06', '07', '11'];

    public function importar(Empresa $empresa, string $contenido, string $origen = 'subida'): DteRecibido
    {
        try {
            $doc = json_decode($contenido, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DteException('El archivo no es un JSON válido.');
        }
        if (! is_array($doc) || ! isset($doc['identificacion']['tipoDte'], $doc['identificacion']['codigoGeneracion'])) {
            throw new DteException('El archivo no es un Documento Tributario Electrónico.');
        }

        $tipo = (string) $doc['identificacion']['tipoDte'];
        $codigo = strtoupper((string) $doc['identificacion']['codigoGeneracion']);
        $existente = DteRecibido::query()->where('codigo_generacion', $codigo)->first();
        if ($existente !== null) {
            return $existente; // ya importado: idempotente
        }
        if (Dte::query()->where('codigo_generacion', $codigo)->exists()) {
            throw new DteException('Ese documento lo emitió esta misma empresa: no es un documento recibido.');
        }
        if (! in_array($tipo, self::TIPOS, true)) {
            throw new DteException("Los documentos del tipo {$tipo} no se registran como recibidos.");
        }

        $firma = $doc['firmaElectronica'] ?? null;
        $sello = $doc['selloRecibido'] ?? null;
        if (! is_string($sello) || ! preg_match('/^[A-Z0-9]{40}$/', $sello)) {
            throw new DteException('El documento no trae un sello de recepción válido: pídale al proveedor el archivo completo.');
        }
        if (! is_string($firma) || substr_count($firma, '.') !== 2) {
            throw new DteException('El documento no trae la firma electrónica.');
        }

        $this->validarReceptor($tipo, $doc, $empresa);

        $sinFirma = $doc;
        unset($sinFirma['firmaElectronica'], $sinFirma['selloRecibido']);
        $firmaCoincide = $this->payload($firma) === $this->normalizar($sinFirma);
        if (! $firmaCoincide) {
            throw new DteException('El contenido del documento no coincide con lo firmado: puede haber sido alterado.');
        }

        $errores = (new ValidadorEsquema((string) config('dte.esquemas')))->validar((string) json_encode($sinFirma), $tipo);
        if ($errores !== []) {
            throw new DteException('El documento no cumple el esquema oficial: '.array_key_first($errores));
        }

        $r = $doc['resumen'] ?? [];
        $iva = $tipo === '03'
            ? (array_values(array_filter((array) ($r['tributos'] ?? []), fn ($t) => ($t['codigo'] ?? null) === '20'))[0]['valor'] ?? 0)
            : ($r['totalIva'] ?? 0);
        $emisor = $doc['emisor'] ?? [];

        $recibido = DteRecibido::query()->create([
            'tipo' => $tipo,
            'codigo_generacion' => $codigo,
            'numero_control' => (string) ($doc['identificacion']['numeroControl'] ?? ''),
            'fecha_emision' => (string) $doc['identificacion']['fecEmi'],
            'emisor_nit' => (string) ($emisor['nit'] ?? ''),
            'emisor_nrc' => $emisor['nrc'] ?? null,
            'emisor_nombre' => (string) ($emisor['nombre'] ?? ''),
            'sello_recibido' => $sello,
            'total_no_suj' => $this->texto($r['totalNoSuj'] ?? 0),
            'total_exenta' => $this->texto($r['totalExenta'] ?? 0),
            'total_gravada' => $this->texto($r['totalGravada'] ?? ($r['totalSujetoRetencion'] ?? 0)),
            'total_iva' => $this->texto($tipo === '07' ? ($r['totalIva'] ?? 0) : $iva),
            'iva_percibido' => $this->texto($r['ivaPerci'] ?? 0),
            'iva_retenido' => $this->texto($tipo === '07' ? ($r['totalIvaRetenido'] ?? 0) : ($r['ivaRete'] ?? 0)),
            'total_pagar' => $this->texto($r['totalPagar'] ?? ($r['totalIvaRetenido'] ?? 0)),
            'json_texto' => $contenido,
            'json_original' => $doc,
            'firma_coincide' => $firmaCoincide,
            'verificado_publico' => $this->verificarPublico($doc, $sello),
            'origen' => $origen,
        ]);
        Bitacora::registrar('dte_recibido.importado', $recibido, ['origen' => $origen, 'tipo' => $tipo]);

        return $recibido;
    }

    /** @param array<string, mixed> $doc */
    private function validarReceptor(string $tipo, array $doc, Empresa $empresa): void
    {
        $receptor = (array) ($doc['receptor'] ?? []);
        $documento = $receptor['nit'] ?? $receptor['numDocumento'] ?? null;

        if ($tipo === '01' && $documento === null) {
            throw new DteException('La factura no identifica al comprador: no sirve como documento de compra de la empresa.');
        }
        if ($documento !== $empresa->nit) {
            throw new DteException('El documento no está emitido a nombre de esta empresa (NIT del receptor distinto).');
        }
    }

    /** Payload del JWS (base64url) como estructura normalizada. */
    private function payload(string $jws): mixed
    {
        $parte = explode('.', $jws)[1];
        $json = base64_decode(strtr($parte, '-_', '+/').str_repeat('=', (4 - strlen($parte) % 4) % 4), true);
        try {
            return $json === false ? null : $this->normalizar(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return null;
        }
    }

    /** Ordena claves y lleva los números a texto exacto para comparar estructuras. */
    private function normalizar(mixed $valor): mixed
    {
        if (is_array($valor)) {
            if (! array_is_list($valor)) {
                ksort($valor);
            }

            return array_map(fn ($v) => $this->normalizar($v), $valor);
        }

        return is_float($valor) || is_int($valor) ? (string) json_encode($valor) : $valor;
    }

    /** @param array<string, mixed> $doc */
    private function verificarPublico(array $doc, string $sello): ?bool
    {
        if (! config('dte.recibidos.verificar_publico')) {
            return null;
        }
        try {
            $dte = (new Dte)->forceFill([
                'ambiente' => (string) ($doc['identificacion']['ambiente'] ?? '01'),
                'codigo_generacion' => strtoupper((string) $doc['identificacion']['codigoGeneracion']),
                'fecha_emision' => (string) $doc['identificacion']['fecEmi'],
            ]);
            $respuesta = Http::timeout(10)->get(CodigoQr::url($dte));

            return $respuesta->successful() && str_contains($respuesta->body(), $sello);
        } catch (Throwable) {
            return null; // sin conexión: queda pendiente de verificar
        }
    }

    private function texto(mixed $numero): string
    {
        return is_float($numero) ? (string) json_encode($numero) : (string) $numero;
    }
}
