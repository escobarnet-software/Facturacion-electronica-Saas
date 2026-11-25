<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Aviso de consumo del plan (80 % y 100 %). Recuerda que la emisión nunca se bloquea. */
class AvisoConsumo extends Mailable
{
    use Queueable;

    public function __construct(public int $porcentaje, public int $documentos, public int $limite, public string $periodo)
    {
        $this->onQueue('entrega');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->porcentaje >= 100
            ? 'Superó los documentos incluidos en su plan'
            : "Ha usado el {$this->porcentaje} % de los documentos de su plan");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.aviso-consumo');
    }
}
