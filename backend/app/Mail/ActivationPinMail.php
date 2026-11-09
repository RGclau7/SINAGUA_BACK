<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Correo con el PIN para activar una cuenta nueva (administrador o cobrador). */
class ActivationPinMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $pin,
        public readonly string $link,
        public readonly int $hours,
        public readonly string $communityName,
        public readonly string $roleLabel,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'SINAGUA: activa tu cuenta');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.activation-pin', text: 'mail.activation-pin-text');
    }
}
