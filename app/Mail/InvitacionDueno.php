<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Invitación para que el dueño cree su contraseña (enlace firmado de un solo uso, 72 h). */
class InvitacionDueno extends Mailable
{
    use Queueable;

    public function __construct(public string $empresa, public string $enlace)
    {
        $this->onQueue('entrega');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Active su cuenta en Escobar Net DTE');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.invitacion-dueno');
    }
}
