<?php

declare(strict_types=1);

namespace App\Mail;

use App\Configuration\ClubSettings;
use App\Configuration\UserRoles;
use App\Models\User;
use App\Security\UserInvitations;
use App\Support\FormOfAddress;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells a new administration user about the account and links to setting a password. */
class UserInvitationMail extends Mailable
{
    private string $clubName;

    private ?string $logoUrl;

    private string $subjectLine;

    public function __construct(private readonly User $user, private readonly string $token, public readonly bool $resent = false)
    {
        $settings = app(ClubSettings::class);
        $club = $settings->data();
        $this->clubName = (string) (($club['short_name'] ?? null) ?: ($club['name'] ?? config('app.name')));
        $this->logoUrl = $settings->logoUrl();
        $this->subjectLine = $resent
            ? FormOfAddress::choose('Neuer Link für dein Benutzerkonto bei ', 'Neuer Link für Ihr Benutzerkonto bei ', $club).$this->clubName
            : FormOfAddress::choose('Für dich wurde ein Benutzerkonto bei ', 'Für Sie wurde ein Benutzerkonto bei ', $club).$this->clubName.' angelegt';
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        $roles = array_values(array_intersect_key(UserRoles::LABELS, array_flip($this->user->roles ?? [])));

        return new Content(view: 'mail.user-invitation', with: [
            'clubName' => $this->clubName,
            'logoUrl' => $this->logoUrl,
            'subjectLine' => $this->subjectLine,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'roles' => $roles,
            'requiresTwoFactor' => array_intersect($this->user->roles ?? [], config('security.privileged_roles', [])) !== [],
            'url' => route('invitation.show', ['token' => $this->token, 'email' => $this->user->email]),
            'loginUrl' => route('login'),
            'validHours' => UserInvitations::validHours(),
            'resent' => $this->resent,
        ]);
    }
}
