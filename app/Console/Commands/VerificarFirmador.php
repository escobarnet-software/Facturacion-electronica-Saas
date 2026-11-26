<?php

namespace App\Console\Commands;

use App\Dte\Credenciales\AlmacenCertificados;
use App\Dte\Firma\FirmadorClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Verifica de punta a punta la conexión con el firmador OFICIAL, sin usar credenciales reales:
 * genera un certificado FICTICIO con el mismo formato que entrega Hacienda (NIT 00000000000000),
 * firma un documento de prueba, comprueba la firma RS512 con la llave pública y borra el certificado.
 */
class VerificarFirmador extends Command
{
    private const NIT = '00000000000000';

    protected $signature = 'dte:firmador:verificar';

    protected $description = 'Firma un documento de prueba con el firmador oficial y verifica la firma (certificado ficticio)';

    public function handle(FirmadorClient $firmador): int
    {
        $this->line('Firmador: '.config('dte.firmador.url'));
        $disco = Storage::disk((string) config('dte.firmador.disco_certificados'));
        $clave = bin2hex(random_bytes(12));
        $llave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($llave === false) {
            $this->error('No se pudo generar la llave de prueba (extensión openssl).');

            return self::FAILURE;
        }
        openssl_pkey_export($llave, $privadaPem);
        $publicaPem = (string) (openssl_pkey_get_details($llave)['key'] ?? '');
        $der = fn (string $pem) => base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', $pem));
        $hash = hash('sha512', $clave);
        $xml = '<CertificadoMH><nit>'.self::NIT.'</nit>'
            .'<publicKey><keyType>PUBLIC</keyType><algorithm>RSA</algorithm><encodied>'.base64_encode($der($publicaPem)).'</encodied><format>X.509</format><clave>'.$hash.'</clave></publicKey>'
            .'<privateKey><keyType>PRIVATE</keyType><algorithm>RSA</algorithm><encodied>'.base64_encode($der((string) $privadaPem)).'</encodied><format>PKCS#8</format><clave>'.$hash.'</clave></privateKey>'
            .'<activo>true</activo></CertificadoMH>';
        AlmacenCertificados::validar($xml);

        $archivo = self::NIT.'.crt';
        $disco->put($archivo, $xml, 'private');
        try {
            $documento = (string) json_encode(['identificacion' => ['version' => 1, 'ambiente' => '00', 'tipoDte' => '01', 'codigoGeneracion' => strtoupper((string) Str::uuid())], 'prueba' => 'verificación del firmador']);
            $jws = $firmador->firmar(self::NIT, $clave, $documento);
            [$cabecera, $carga, $firma] = explode('.', $jws) + [null, null, null];
            $b64 = fn (?string $s) => (string) base64_decode(strtr((string) $s, '-_', '+/').str_repeat('=', (4 - strlen((string) $s) % 4) % 4));
            $valida = openssl_verify($cabecera.'.'.$carga, $b64($firma), $publicaPem, OPENSSL_ALGO_SHA512) === 1;
            $igual = json_decode($b64($carga), true) == json_decode($documento, true);

            $this->table(['Comprobación', 'Resultado'], [
                ['El firmador responde', 'Sí'],
                ['Algoritmo', (string) (json_decode($b64($cabecera), true)['alg'] ?? '?')],
                ['Firma RS512 válida', $valida ? 'Sí' : 'NO'],
                ['Lo firmado es exactamente el documento', $igual ? 'Sí' : 'NO'],
            ]);
            try {
                $firmador->firmar(self::NIT, 'contraseña-equivocada', $documento);
                $this->error('El firmador aceptó una contraseña equivocada.');

                return self::FAILURE;
            } catch (Throwable) {
                $this->line('Con una contraseña equivocada el firmador rechaza la firma: correcto.');
            }

            return $valida && $igual ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->error('No se pudo firmar: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $disco->delete($archivo);
        }
    }
}
