<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Demo\DemoAccounts;
use App\Models\DemoMail;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Public mailbox of the demo. It has to be public: visitors read the login
 * link of the self-service portal here before they are signed in. All demo
 * addresses are fictitious, and the banner warns against entering real data.
 */
class DemoMailboxController extends Controller
{
    public function __construct()
    {
        abort_unless(DemoAccounts::enabled(), 404);
    }

    public function index(Request $request): Response
    {
        $recipient = mb_substr(trim((string) $request->query('empfaenger', '')), 0, 255);
        $mails = DemoMail::query()
            ->when($recipient !== '', fn ($query) => $query->where('recipients', 'like', '%'.addcslashes($recipient, '%_\\').'%'))
            ->orderByDesc('id')->limit(100)->get(['id', 'subject', 'sender', 'recipients', 'created_at']);
        $selected = $request->integer('mail') > 0 ? DemoMail::query()->find($request->integer('mail')) : null;

        return Inertia::render('public/DemoMailbox', [
            'recipient' => $recipient,
            'mails' => $mails->map(fn (DemoMail $mail): array => [
                'id' => $mail->id,
                'subject' => $mail->subject,
                'to' => implode(', ', $mail->recipients['to']),
                'createdAt' => $mail->created_at?->toIso8601String(),
            ]),
            'selected' => $selected ? [
                'id' => $selected->id,
                'subject' => $selected->subject,
                'sender' => $selected->sender,
                'to' => implode(', ', $selected->recipients['to']),
                'cc' => implode(', ', $selected->recipients['cc']),
                'bcc' => implode(', ', $selected->recipients['bcc']),
                'createdAt' => $selected->created_at?->toIso8601String(),
                'hasHtml' => $selected->html !== null,
                'text' => $selected->text,
                'attachments' => array_map(fn (array $attachment): array => [
                    'name' => $attachment['name'], 'size' => $attachment['size'], 'available' => $attachment['content'] !== null,
                ], $selected->attachments),
            ] : null,
        ]);
    }

    /** HTML body for a sandboxed iframe: no scripts, forms, plugins or navigation of the parent page. */
    public function html(DemoMail $mail): HttpResponse
    {
        abort_if($mail->html === null, 404);

        // Links open in a new tab; inside the sandbox the app session is not available.
        return response('<base target="_blank">'.$mail->html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "sandbox allow-popups allow-popups-to-escape-sandbox; default-src 'none'; img-src 'self' data: https:; style-src 'unsafe-inline'; font-src data:; frame-ancestors 'self'",
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Always a download, so an HTML or SVG attachment never runs in the page origin. */
    public function attachment(DemoMail $mail, int $index): HttpResponse
    {
        $attachment = $mail->attachments[$index] ?? null;
        abort_if($attachment === null || $attachment['content'] === null, 404);
        $name = str_replace(['/', '\\', "\r", "\n"], '', $attachment['name']) ?: 'Anhang';

        return response((string) base64_decode($attachment['content'], true), 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, str_replace('%', '', Str::ascii($name)) ?: 'Anhang'),
            'Cache-Control' => 'no-store',
        ]);
    }
}
