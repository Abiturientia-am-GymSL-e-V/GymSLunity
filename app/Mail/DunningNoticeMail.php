<?php

declare(strict_types=1);

namespace App\Mail;

use App\Configuration\ClubSettings;
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

    private ?string $logoUrl = null;

    public function __construct(public readonly Member $member)
    {
        $settings = app(ClubSettings::class);
        $this->club = $settings->data();
        $this->logoUrl = $settings->logoUrl();
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
            'logoUrl' => $this->logoUrl,
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
