<?php

namespace App\Mail;

use App\Models\ClubSetting;
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

    private int $settingsVersion;

    public function __construct(public readonly Contribution $contribution)
    {
        $settings = ClubSetting::current();
        $this->club = $settings->data;
        $this->settingsVersion = $settings->version;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: PublicPageTemplates::render('contribution_invoice_mail_subject', $this->club).' · '.$this->contribution->invoice_number);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.contribution-invoice', with: [
            'clubName' => (string) (($this->club['short_name'] ?? null) ?: ($this->club['name'] ?? config('app.name'))),
            'logoUrl' => ! empty($this->club['logo_path']) ? route('branding.logo', ['v' => $this->settingsVersion]) : null,
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
