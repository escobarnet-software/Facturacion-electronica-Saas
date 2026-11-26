<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Envía un correo de prueba con la configuración actual y dice si salió (sin mostrar contraseñas). */
class ProbarCorreo extends Command
{
    protected $signature = 'plataforma:correo:probar {destino : correo que recibirá la prueba}';

    protected $description = 'Envía un correo de prueba para verificar la configuración SMTP';

    public function handle(): int
    {
        $destino = (string) $this->argument('destino');
        if (! filter_var($destino, FILTER_VALIDATE_EMAIL)) {
            $this->error('Escriba un correo válido.');

            return self::INVALID;
        }
        $mailer = (string) config('mail.default');
        $this->table(['Configuración', 'Valor'], [
            ['Transporte', $mailer],
            ['Servidor', (string) config("mail.mailers.{$mailer}.host").':'.config("mail.mailers.{$mailer}.port")],
            ['Usuario', config("mail.mailers.{$mailer}.username") ? 'configurado' : '—'],
            ['Remitente', (string) config('mail.from.address')],
        ]);

        try {
            Mail::raw("Este es un correo de prueba de Escobar Net DTE.\n\nSi lo recibe, la plataforma ya puede enviar documentos, invitaciones y avisos a sus clientes.\n\nEnviado el ".now('America/El_Salvador')->format('d/m/Y H:i'), function ($m) use ($destino) {
                $m->to($destino)->subject('Prueba de correo de Escobar Net DTE');
            });
        } catch (Throwable $e) {
            $this->error('No se pudo enviar: '.self::explicar($e->getMessage()));

            return self::FAILURE;
        }
        $this->info("Correo de prueba enviado a {$destino}. Revise la bandeja de entrada y la de correo no deseado.");

        return self::SUCCESS;
    }

    /** Traduce los errores SMTP más comunes a una explicación útil (sin repetir credenciales). */
    public static function explicar(string $mensaje): string
    {
        return match (true) {
            str_contains($mensaje, '535') || str_contains(strtolower($mensaje), 'authentication') => 'el servidor rechazó el usuario o la contraseña SMTP (en Gmail use una contraseña de aplicación).',
            str_contains(strtolower($mensaje), 'connection could not be established') || str_contains(strtolower($mensaje), 'connection refused') => 'no se pudo conectar al servidor SMTP: revise MAIL_HOST y MAIL_PORT, o si su red bloquea ese puerto.',
            str_contains($mensaje, '550') || str_contains(strtolower($mensaje), 'sender') => 'el servidor no acepta ese remitente: verifique MAIL_FROM_ADDRESS en su proveedor.',
            default => mb_substr(preg_replace('/(password|pass|pwd)=\S+/i', '$1=***', $mensaje) ?? $mensaje, 0, 300),
        };
    }
}
