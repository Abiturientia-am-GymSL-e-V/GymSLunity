<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SelfServiceAccessMail extends Mailable
{
    public function __construct(public string $token, public string $purpose) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->purpose === 'email' ? 'Neue E-Mail-Adresse bestätigen' : 'Dein Zugang zum Mitgliederbereich');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.selfservice-access', with: ['url' => rtrim(config('app.url'), '/').'/selfservice/zugang#token='.$this->token]);
    }
}
