<?php

namespace App\Support\Seguridad;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * Cifra los secretos de los clientes (contraseña de la API de Hacienda y de la
 * llave privada) con una llave dedicada, DTE_SECRETS_KEY, separada de APP_KEY.
 * Así, filtrar APP_KEY no basta para leer las credenciales de los clientes.
 */
final class Secretos
{
    private ?Encrypter $encrypter = null;

    public function cifrar(#[\SensitiveParameter] string $valor): string
    {
        return $this->encrypter()->encryptString($valor);
    }

    public function descifrar(string $cifrado): string
    {
        return $this->encrypter()->decryptString($cifrado);
    }

    private function encrypter(): Encrypter
    {
        if ($this->encrypter !== null) {
            return $this->encrypter;
        }

        $llave = (string) config('dte.secrets_key');
        if ($llave === '') {
            throw new RuntimeException('Falta DTE_SECRETS_KEY en el .env (llave para cifrar credenciales de clientes).');
        }
        if (str_starts_with($llave, 'base64:')) {
            $llave = (string) base64_decode(substr($llave, 7), true);
        }
        if ($llave === config('app.key') || 'base64:'.base64_encode($llave) === config('app.key')) {
            throw new RuntimeException('DTE_SECRETS_KEY debe ser distinta de APP_KEY.');
        }

        return $this->encrypter = new Encrypter($llave, 'aes-256-gcm');
    }
}
