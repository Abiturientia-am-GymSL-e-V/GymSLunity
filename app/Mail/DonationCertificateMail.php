<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\ClubSetting;
use App\Models\DonationCertificate;
use App\Support\FormOfAddress;
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
        return new Envelope(subject: FormOfAddress::choose('Deine Zuwendungsbestätigung ', 'Ihre Zuwendungsbestätigung ').$this->certificate->certificate_number);
    }

    public function content(): Content
    {
        $settings = ClubSetting::current();

        return new Content(view: 'mail.donation-certificate', with: [
            'clubName' => (string) ($this->certificate->snapshot['club']['name'] ?? config('app.name')),
            'logoUrl' => ! empty($settings->data['logo_path']) ? route('branding.logo', ['v' => $settings->version]) : null,
        ]);
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
