<?php

declare(strict_types=1);

namespace App\Mail;

use App\Configuration\ClubSettings;
use App\Models\FinanceMandate;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class FinanceMandateMail extends Mailable
{
    public function __construct(public FinanceMandate $mandate) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'SEPA-Lastschriftmandat '.$this->mandate->mandate_reference);
    }

    public function content(): Content
    {
        $settings = app(ClubSettings::class);

        return new Content(view: 'mail.finance-mandate', with: [
            'clubName' => ($settings->text('name') ?: (string) config('app.name')),
            'logoUrl' => $settings->logoUrl(),
            'signingUrl' => route('forms.mandates.sign', ['token' => $this->mandate->signingToken()]),
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn (): string => $this->mandate->pdf(), $this->mandate->filename())->withMime('application/pdf')];
    }
}
