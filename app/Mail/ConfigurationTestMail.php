<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConfigurationTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $driverLabel,
        public readonly string $recipient,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'GymSLunity – E-Mail-Konfiguration erfolgreich');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.configuration-test');
    }
}
