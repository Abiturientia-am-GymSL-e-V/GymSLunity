<?php

declare(strict_types=1);

namespace App\Mail;

use App\Configuration\ClubSettings;
use App\Models\Contribution;
use App\Payments\ContributionInvoice;
use App\PublicSite\PublicPageTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContributionInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @var array<string, mixed> */
    private array $club;

    private ?string $logoUrl = null;

    public function __construct(public readonly Contribution $contribution)
    {
        $settings = app(ClubSettings::class);
        $this->club = $settings->data();
        $this->logoUrl = $settings->logoUrl();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: PublicPageTemplates::render('contribution_invoice_mail_subject', $this->club).' · '.$this->contribution->invoice_number);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.contribution-invoice', with: [
            'clubName' => (string) (($this->club['short_name'] ?? null) ?: ($this->club['name'] ?? config('app.name'))),
            'logoUrl' => $this->logoUrl,
            'messageText' => PublicPageTemplates::render('contribution_invoice_mail_text', $this->club),
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(
            fn (): string => app(ContributionInvoice::class)->pdf($this->contribution),
            ($this->contribution->invoice_number ?? 'Beitragsrechnung').'.pdf',
        )->withMime('application/pdf')];
    }
}
