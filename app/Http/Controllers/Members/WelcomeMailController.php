<?php

declare(strict_types=1);

namespace App\Http\Controllers\Members;

use App\Http\Controllers\Controller;
use App\Http\Requests\Members\SendWelcomeMailsRequest;
use App\Members\WelcomeMails;
use App\Support\IdempotencyKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class WelcomeMailController extends Controller
{
    public function __invoke(SendWelcomeMailsRequest $request, WelcomeMails $welcomeMails): RedirectResponse
    {
        if (! $welcomeMails->available()) {
            throw ValidationException::withMessages(['members' => 'Willkommensmails setzen den aktivierten Mitglieder-Selfservice voraus.']);
        }
        // A resent form must not start a second run.
        if (! IdempotencyKey::claim('welcome_mail', (string) $request->validated('request_id'))) {
            Inertia::flash('toast', ['type' => 'info', 'message' => 'Dieser Versand wurde bereits ausgeführt.']);

            return back();
        }
        $result = $welcomeMails->send(
            array_values(array_map('intval', $request->validated('members'))),
            $request->boolean('resend'),
            $request->user(),
        );

        $parts = [$result['sent'].' '.($result['sent'] === 1 ? 'Willkommensmail versendet' : 'Willkommensmails versendet')];
        if ($result['skipped'] > 0) {
            $parts[] = $result['skipped'].' übersprungen';
        }
        if ($result['failed'] > 0) {
            $parts[] = $result['failed'].' fehlgeschlagen';
        }
        $message = implode(', ', $parts).'.';
        if ($result['skipped'] + $result['failed'] > 0) {
            $message .= ' Details stehen im Kommunikationsverlauf.';
        }
        Inertia::flash('toast', [
            'type' => $result['failed'] > 0 ? 'error' : ($result['skipped'] > 0 ? 'warning' : 'success'),
            'message' => $message,
        ]);

        return back();
    }
}
