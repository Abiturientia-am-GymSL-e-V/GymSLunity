<?php

namespace App\Mail;

use App\Models\ClubSetting;
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
        $settings = ClubSetting::current();

        return new Content(view: 'mail.configuration-test', with: [
            'clubName' => (string) (($settings->data['short_name'] ?? null) ?: ($settings->data['name'] ?? config('app.name'))),
            'logoUrl' => ! empty($settings->data['logo_path']) ? route('branding.logo', ['v' => $settings->version]) : null,
        ]);
    }
}
