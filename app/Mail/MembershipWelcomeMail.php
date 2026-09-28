<?php

declare(strict_types=1);

namespace App\Mail;

use App\Configuration\ClubSettings;
use App\PublicSite\PublicPageTemplates;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MembershipWelcomeMail extends Mailable
{
    /** @var array<string, mixed> */
    private array $club;

    private ?string $logoUrl = null;

    private string $subjectLine;

    private string $messageText;

    public function __construct(public readonly int $memberNumber, public readonly string $applicationPdf)
    {
        $settings = app(ClubSettings::class);
        $this->club = $settings->data();
        $this->logoUrl = $settings->logoUrl();
        $this->subjectLine = PublicPageTemplates::render('welcome_mail_subject', $this->club);
        $this->messageText = PublicPageTemplates::render('welcome_mail_text', $this->club);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.membership-welcome', with: [
            'clubName' => (string) (($this->club['short_name'] ?? null) ?: ($this->club['name'] ?? config('app.name'))),
            'logoUrl' => $this->logoUrl,
            'subjectLine' => $this->subjectLine,
            'messageText' => $this->messageText,
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn (): string => $this->applicationPdf,
                'Mitgliedsantrag-'.$this->memberNumber.'.pdf',
            )->withMime('application/pdf'),
        ];
    }
}
