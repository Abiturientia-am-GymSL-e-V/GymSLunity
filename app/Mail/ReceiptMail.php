<?php

namespace App\Mail;

use App\Models\Receipt;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ReceiptMail extends Mailable
{
    public function __construct(public Receipt $receipt, public string $edition) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Quittung '.$this->receipt->receipt_number.' – '.($this->edition === 'original' ? 'Original' : 'Kopie'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.receipt');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn (): string => $this->receipt->pdf($this->edition), $this->receipt->filename($this->edition))->withMime('application/pdf')];
    }
}
