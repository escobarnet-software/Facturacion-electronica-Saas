<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Aviso de acceso a una empresa. Si la persona es nueva, trae el enlace firmado (72 h, un solo uso)
 * para crear su contraseña; si ya tenía cuenta, solo el enlace para entrar.
 */
class AccesoEmpresa extends Mailable
{
    use Queueable;

    public function __construct(
        public string $empresa,
        public string $rol,
        public string $invitadoPor,
        public string $enlace,
        public bool $cuentaNueva,
    ) {
        $this->onQueue('entrega');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->cuentaNueva
            ? "Invitación a {$this->empresa} en Escobar Net DTE"
            : "Ahora tiene acceso a {$this->empresa} en Escobar Net DTE");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.acceso-empresa');
    }
}
