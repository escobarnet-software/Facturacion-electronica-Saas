<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AlertaPlataforma extends Mailable
{
    use Queueable;

    public function __construct(public string $mensaje) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Escobar Net DTE] Alerta: '.mb_substr($this->mensaje, 0, 80));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.alerta-plataforma');
    }
}
