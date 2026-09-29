<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asks for the password again before exports and critical actions, with a
 * shorter window than the general confirmation. After confirming, the user
 * returns to the page they came from and repeats the action, because the
 * protected request may be a POST or a file download.
 */
class RequireRecentPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        if (self::recentlyConfirmed($request)) {
            return $next($request);
        }

        $message = 'Bitte zuerst das Passwort bestätigen und die Aktion danach erneut ausführen.';
        if (! $request->acceptsHtml()) {
            return response()->json(['message' => $message], 423);
        }
        $request->session()->put('url.intended', url()->previous(route('dashboard')));

        return redirect()->route('password.confirm');
    }

    public static function recentlyConfirmed(Request $request): bool
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        return now()->getTimestamp() - $confirmedAt < (int) config('security.reconfirm_seconds', 900);
    }
}
