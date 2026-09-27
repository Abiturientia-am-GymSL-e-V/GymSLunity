<?php

namespace App\Mail;

use App\Models\ClubSetting;
use App\Models\Contribution;
use App\Models\Member;
use App\Payments\DunningNotices;
use App\Support\FormOfAddress;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DunningNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @var array<string, mixed> */
    private array $club;

    private int $settingsVersion;

    public function __construct(public readonly Member $member)
    {
        $settings = ClubSetting::current();
        $this->club = $settings->data;
        $this->settingsVersion = $settings->version;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Zahlungserinnerung · '.($this->club['short_name'] ?? $this->club['name'] ?? config('app.name')));
    }

    public function content(): Content
    {
        $openCents = $this->member->contributionAccount->contributions
            ->sum(fn (Contribution $item): int => $item->remainingCents());
        $giroCode = app(DunningNotices::class)->giroCode($this->member, $this->club);

        return new Content(view: 'mail.dunning-notice', with: [
            'clubName' => (string) (($this->club['short_name'] ?? null) ?: ($this->club['name'] ?? config('app.name'))),
            'logoUrl' => ! empty($this->club['logo_path']) ? route('branding.logo', ['v' => $this->settingsVersion]) : null,
            'openCents' => $openCents,
            'giroCode' => $giroCode,
            'formalAddress' => FormOfAddress::isFormal($this->club),
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(
            fn (): string => app(DunningNotices::class)->memberPdf($this->member),
            'zahlungserinnerung-'.$this->member->member_number.'.pdf',
        )->withMime('application/pdf')];
    }
}
