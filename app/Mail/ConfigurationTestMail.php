<?php

declare(strict_types=1);

namespace App\Mail;

use App\Configuration\ClubSettings;
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
        $settings = app(ClubSettings::class);

        return new Content(view: 'mail.configuration-test', with: [
            'clubName' => $settings->displayName() ?? (string) config('app.name'),
            'logoUrl' => $settings->logoUrl(),
        ]);
    }
}
