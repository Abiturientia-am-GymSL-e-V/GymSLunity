<?php

namespace App\Mail;

use App\Models\DonationCertificate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DonationCertificateMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly DonationCertificate $certificate) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Ihre Zuwendungsbestätigung '.$this->certificate->certificate_number);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.donation-certificate');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(
            fn (): string => $this->certificate->pdf(),
            $this->certificate->certificate_number.'.pdf',
        )->withMime('application/pdf')];
    }
}
