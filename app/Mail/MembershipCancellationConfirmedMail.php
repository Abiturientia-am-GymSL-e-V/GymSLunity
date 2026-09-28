<?php

declare(strict_types=1);

namespace App\Mail;

use App\Configuration\ClubSettings;
use App\Support\FormOfAddress;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MembershipCancellationConfirmedMail extends Mailable
{
    /** @var array<string, mixed> */
    private array $club;

    private ?string $logoUrl = null;

    public function __construct(
        public readonly string $memberName,
        public readonly string $exitDate,
    ) {
        $settings = app(ClubSettings::class);
        $this->club = $settings->data();
        $this->logoUrl = $settings->logoUrl();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: FormOfAddress::choose('Bestätigung deiner Kündigung', 'Bestätigung Ihrer Kündigung', $this->club));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.membership-cancellation-confirmed', with: [
            'clubName' => (string) (($this->club['short_name'] ?? null) ?: ($this->club['name'] ?? config('app.name'))),
            'logoUrl' => $this->logoUrl,
            'memberName' => $this->memberName,
            'formattedExitDate' => CarbonImmutable::parse($this->exitDate)->format('d.m.Y'),
        ]);
    }
}
