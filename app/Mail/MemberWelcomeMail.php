<?php

declare(strict_types=1);

namespace App\Mail;

use App\Communication\CommunicationTemplate;
use App\Configuration\ClubSettings;
use App\Models\Member;
use App\PublicSite\PublicPageTemplates;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Explains how a member signs in to and uses the self-service portal. */
class MemberWelcomeMail extends Mailable
{
    private string $clubName;

    private ?string $logoUrl;

    private string $subjectLine;

    private string $messageText;

    private string $email;

    private int $memberNumber;

    public function __construct(Member $member, public readonly bool $addressChangeRequired)
    {
        $settings = app(ClubSettings::class);
        $club = $settings->data();
        $templates = app(CommunicationTemplate::class);
        $defaults = PublicPageTemplates::defaults();
        $this->clubName = (string) (($club['short_name'] ?? null) ?: ($club['name'] ?? config('app.name')));
        $this->logoUrl = $settings->logoUrl();
        $this->subjectLine = trim($templates->render((string) ($club['member_welcome_mail_subject'] ?? $defaults['member_welcome_mail_subject']), $member));
        $this->messageText = $templates->render((string) ($club['member_welcome_mail_text'] ?? $defaults['member_welcome_mail_text']), $member);
        $this->email = mb_strtolower(trim((string) $member->email));
        $this->memberNumber = (int) $member->member_number;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.member-welcome', with: [
            'clubName' => $this->clubName,
            'logoUrl' => $this->logoUrl,
            'subjectLine' => $this->subjectLine,
            'preheader' => mb_strimwidth(preg_replace('/\s+/u', ' ', $this->messageText) ?? '', 0, 140, '…'),
            'messageText' => $this->messageText,
            'addressChangeRequired' => $this->addressChangeRequired,
            'email' => $this->email,
            'memberNumber' => $this->memberNumber,
            'url' => rtrim((string) config('app.url'), '/').'/selfservice/zugang',
        ]);
    }
}
