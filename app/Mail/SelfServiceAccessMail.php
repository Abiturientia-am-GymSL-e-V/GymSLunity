<?php

namespace App\Mail;

use App\Models\ClubSetting;
use App\PublicSite\PublicPageTemplates;
use App\Support\FormOfAddress;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SelfServiceAccessMail extends Mailable
{
    /** @var array<string, mixed> */
    private array $club;

    private string $subjectLine;

    private string $messageText;

    public function __construct(public string $token, public string $purpose)
    {
        $this->club = ClubSetting::current()->data;
        $join = $purpose === 'join';
        $this->subjectLine = $purpose === 'email'
            ? 'Neue E-Mail-Adresse bestätigen'
            : PublicPageTemplates::render($join ? 'join_mail_subject' : 'member_access_mail_subject', $this->club);
        $this->messageText = $purpose === 'email'
            ? FormOfAddress::choose(
                'Bestätige über die Schaltfläche deine neue E-Mail-Adresse. Die bisherige Adresse bleibt bis zur erfolgreichen Bestätigung gültig.',
                'Bestätigen Sie über die Schaltfläche Ihre neue E-Mail-Adresse. Die bisherige Adresse bleibt bis zur erfolgreichen Bestätigung gültig.',
                $this->club,
            )
            : PublicPageTemplates::render($join ? 'join_mail_text' : 'member_access_mail_text', $this->club);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        $confirmationPath = $this->purpose === 'join'
            ? '/selfservice/mitglied-werden'
            : '/selfservice/zugang';
        $directConfirmation = $this->purpose === 'email';

        return new Content(view: 'mail.selfservice-access', with: [
            'url' => $directConfirmation
                ? route('selfservice.email.confirm', ['token' => $this->token])
                : rtrim(config('app.url'), '/').$confirmationPath.'#token='.$this->token,
            'clubName' => (string) (($this->club['short_name'] ?? null) ?: ($this->club['name'] ?? config('app.name'))),
            'logoUrl' => ! empty($this->club['logo_path']) ? route('branding.logo', ['v' => ClubSetting::current()->version]) : null,
            'messageText' => $this->messageText,
            'directConfirmation' => $directConfirmation,
            'confirmationPageLabel' => $this->purpose === 'join'
                ? 'die Seite „Mitglied werden“'
                : 'den Mitgliederzugang',
            'actionLabel' => match ($this->purpose) {
                'join' => 'E-Mail-Adresse bestätigen',
                'email' => 'Neue E-Mail-Adresse bestätigen',
                default => 'Mitgliederzugang öffnen',
            },
        ]);
    }
}
