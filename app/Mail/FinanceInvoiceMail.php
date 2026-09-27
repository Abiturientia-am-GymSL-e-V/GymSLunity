<?php

namespace App\Mail;

use App\Models\ClubSetting;
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
        $settings = ClubSetting::current();

        return new Content(view: 'mail.finance-invoice', with: [
            'clubName' => (string) ($this->invoice->snapshot['seller']['name'] ?? config('app.name')),
            'logoUrl' => ! empty($settings->data['logo_path']) ? route('branding.logo', ['v' => $settings->version]) : null,
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
