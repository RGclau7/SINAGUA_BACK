<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Correo con el PIN para confirmar la entrada del administrador en un equipo nuevo. */
class LoginPinMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $pin,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'SINAGUA: tu código para entrar');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.login-pin', text: 'mail.login-pin-text');
    }
}
