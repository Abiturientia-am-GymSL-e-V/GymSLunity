<?php

namespace App\Mail;

use App\Models\Contribution;
use App\Payments\ContributionInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContributionInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Contribution $contribution) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Beitragsrechnung '.$this->contribution->invoice_number);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.contribution-invoice');
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
