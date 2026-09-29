<?php

declare(strict_types=1);

namespace App\Demo;

use App\Models\DemoMail;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

/** Stores outgoing mails for the demo mailbox instead of delivering them. */
final class DemoMailTransport extends AbstractTransport
{
    /** Older mails are removed, so visitors cannot fill the database. */
    public const KEEP = 500;

    /** Larger attachments are listed without their content. */
    private const MAX_ATTACHMENT_BYTES = 5 * 1024 * 1024;

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();
        if (! $original instanceof Message) {
            // Laravel always sends Email objects; keep anything else readable as text.
            $this->store('', '', [], $original->toString(), null, []);

            return;
        }
        $email = MessageConverter::toEmail($original);
        $html = self::body($email->getHtmlBody());
        $attachments = [];
        foreach ($email->getAttachments() as $part) {
            $body = $part->getBody();
            // Inline images (cid:) become data URIs, the mailbox serves no parts separately.
            if ($html !== null && $part->getDisposition() === 'inline' && $part->hasContentId()) {
                $html = str_replace('cid:'.$part->getContentId(), 'data:'.$part->getContentType().';base64,'.base64_encode($body), $html);

                continue;
            }
            $attachments[] = [
                'name' => $part->getFilename() ?? 'Anhang',
                'mime' => $part->getContentType(),
                'size' => strlen($body),
                'content' => strlen($body) <= self::MAX_ATTACHMENT_BYTES ? base64_encode($body) : null,
            ];
        }
        $addresses = fn (array $list): array => array_values(array_map(fn (Address $address): string => $address->toString(), $list));

        $this->store(
            (string) $email->getSubject(),
            implode(', ', $addresses($email->getFrom())),
            ['to' => $addresses($email->getTo()), 'cc' => $addresses($email->getCc()), 'bcc' => $addresses($email->getBcc())],
            self::body($email->getTextBody()),
            $html,
            $attachments,
        );
    }

    /**
     * @param  array{to?: list<string>, cc?: list<string>, bcc?: list<string>}  $recipients
     * @param  list<array{name: string, mime: string, size: int, content: string|null}>  $attachments
     */
    private function store(string $subject, string $sender, array $recipients, ?string $text, ?string $html, array $attachments): void
    {
        DemoMail::query()->create([
            'subject' => mb_substr($subject, 0, 255),
            'sender' => mb_substr($sender, 0, 255),
            'recipients' => ['to' => [], 'cc' => [], 'bcc' => [], ...$recipients],
            'html' => $html,
            'text' => $text,
            'attachments' => $attachments,
        ]);
        $oldest = DemoMail::query()->orderByDesc('id')->skip(self::KEEP)->value('id');
        if ($oldest !== null) {
            DemoMail::query()->where('id', '<=', $oldest)->delete();
        }
    }

    /** @param resource|string|null $body */
    private static function body(mixed $body): ?string
    {
        if ($body === null || is_string($body)) {
            return $body;
        }
        $contents = stream_get_contents($body);

        return $contents === false ? null : $contents;
    }

    public function __toString(): string
    {
        return 'demo://mailbox';
    }
}
