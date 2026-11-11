<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo de entrega del DTE (Normativa regla 11): SOLO información de la entrega, con el
 * Archivo DTE ({código}.json) y la representación gráfica ({código}.pdf) adjuntos.
 * Prohibido incluir publicidad, promociones o encuestas.
 */
class EntregaDte extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{emisor: string, nitEmisor: string, tipo: string, numeroControl: string, codigoGeneracion: string, sello: string|null, fecha: string, total: string|null, enlace: string, contingencia: bool}  $datos
     */
    public function __construct(
        public array $datos,
        public string $json,
        public string $pdf,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Documento Tributario Electrónico: {$this->datos['tipo']} {$this->datos['numeroControl']}");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.entrega-dte', with: ['d' => $this->datos]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $codigo = $this->datos['codigoGeneracion'];

        return [
            Attachment::fromData(fn () => $this->json, "{$codigo}.json")->withMime('application/json'),
            Attachment::fromData(fn () => $this->pdf, "{$codigo}.pdf")->withMime('application/pdf'),
        ];
    }

    public static function nombreArchivo(string $codigo, string $extension): string
    {
        return "{$codigo}.{$extension}";
    }
}
