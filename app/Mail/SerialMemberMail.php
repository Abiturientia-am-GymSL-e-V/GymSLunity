<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SerialMemberMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $renderedSubject,
        public readonly string $renderedBody,
        /** @var list<array{name: string, mime: string, contents: string}> */
        public readonly array $mailAttachments = [],
    ) {
        $withBreaks = preg_replace('/<\s*(br\s*\/?>|\/p\s*>|\/li\s*>|\/h[1-3]\s*>)/i', "\n", $renderedBody) ?? $renderedBody;
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->renderedText = trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    public readonly string $renderedText;

    public function envelope(): Envelope
    {
        $subject = preg_replace('/[\r\n]+/', ' ', $this->renderedSubject) ?? $this->renderedSubject;

        return new Envelope(subject: trim($subject));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.serial-member', text: 'mail.serial-member-text');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return array_map(
            fn (array $file): Attachment => Attachment::fromData(fn (): string => $file['contents'], $file['name'])->withMime($file['mime']),
            $this->mailAttachments,
        );
    }
}
