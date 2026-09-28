<?php

declare(strict_types=1);

namespace App\Mail;

use App\Configuration\ClubSettings;
use App\Models\FinanceInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FinanceInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly FinanceInvoice $invoice) {}

    public function envelope(): Envelope
    {
        $label = $this->invoice->document_type === 'cancellation' ? 'Stornorechnung ' : 'Rechnung ';

        return new Envelope(subject: $label.$this->invoice->invoice_number);
    }

    public function content(): Content
    {
        $settings = app(ClubSettings::class);

        return new Content(view: 'mail.finance-invoice', with: [
            'clubName' => (string) ($this->invoice->snapshot['seller']['name'] ?? config('app.name')),
            'logoUrl' => $settings->logoUrl(),
            'documentLabel' => $this->invoice->document_type === 'cancellation' ? 'Stornorechnung' : 'Rechnung',
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->invoice->pdf(), $this->invoice->filename('pdf'))->withMime('application/pdf'),
            Attachment::fromData(fn (): string => $this->invoice->xrechnung(), $this->invoice->filename('xrechnung'))->withMime('application/xml'),
        ];
    }
}
