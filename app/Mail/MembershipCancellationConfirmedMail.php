<?php

namespace App\Mail;

use App\Models\ClubSetting;
use App\Support\FormOfAddress;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MembershipCancellationConfirmedMail extends Mailable
{
    /** @var array<string, mixed> */
    private array $club;

    private int $settingsVersion;

    public function __construct(
        public readonly string $memberName,
        public readonly string $exitDate,
    ) {
        $settings = ClubSetting::current();
        $this->club = $settings->data;
        $this->settingsVersion = $settings->version;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: FormOfAddress::choose('Bestätigung deiner Kündigung', 'Bestätigung Ihrer Kündigung', $this->club));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.membership-cancellation-confirmed', with: [
            'clubName' => (string) (($this->club['short_name'] ?? null) ?: ($this->club['name'] ?? config('app.name'))),
            'logoUrl' => ! empty($this->club['logo_path']) ? route('branding.logo', ['v' => $this->settingsVersion]) : null,
            'memberName' => $this->memberName,
            'formattedExitDate' => CarbonImmutable::parse($this->exitDate)->format('d.m.Y'),
        ]);
    }
}
