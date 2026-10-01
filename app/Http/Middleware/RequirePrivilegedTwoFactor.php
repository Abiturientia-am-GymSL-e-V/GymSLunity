<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Demo\DemoAccounts;
use App\Support\FormOfAddress;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequirePrivilegedTwoFactor
{
    /** Set when the current login has actually used a second factor, not merely configured one. */
    public const VERIFIED = 'security.second_factor_verified';

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || count(array_intersect($user->roles ?? [], config('security.privileged_roles', []))) === 0) {
            return $next($request);
        }

        // actingAs() deliberately does not emulate a completed login. Dedicated
        // middleware tests set this marker; browser logins receive it via Login.
        if (app()->runningUnitTests() && ! $request->session()->has('security.authenticated_at')) {
            return $next($request);
        }

        if (Auth::viaRemember()) {
            return $this->logout($request, 'Privilegierte Konten müssen sich vollständig neu anmelden.');
        }

        // Visitors of the public demo share these accounts and cannot share a second factor.
        if ($request->session()->get(self::VERIFIED) === true || DemoAccounts::isDemoUser($user)) {
            return $next($request);
        }

        if (! $user->hasRequiredSecondFactor()) {
            return $this->isSetupRoute($request) ? $next($request) : to_route('security.setup')->with('status', FormOfAddress::choose(
                'Für privilegierte Konten ist eine zusätzliche Anmeldemethode verpflichtend. Bitte richte jetzt TOTP oder einen Passkey ein.',
                'Für privilegierte Konten ist eine zusätzliche Anmeldemethode verpflichtend. Bitte richten Sie jetzt TOTP oder einen Passkey ein.',
            ));
        }

        // TOTP is already demanded by Fortify during login. Sessions without the
        // marker started before this check existed or before the role was granted.
        if (! $user->hasPasskeysEnabled()) {
            return $this->logout($request, FormOfAddress::choose(
                'Bitte melde dich erneut an und bestätige die Anmeldung mit deinem Authentifizierungscode.',
                'Bitte melden Sie sich erneut an und bestätigen Sie die Anmeldung mit Ihrem Authentifizierungscode.',
            ));
        }

        if ($request->routeIs('passkey.challenge', 'passkey.confirm-options', 'passkey.confirm', 'logout')) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            redirect()->setIntendedUrl($request->fullUrl());
        }

        return to_route('passkey.challenge');
    }

    private function logout(Request $request, string $message): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login')->withErrors(['email' => $message]);
    }

    private function isSetupRoute(Request $request): bool
    {
        return $request->routeIs(
            'security.setup',
            'password.confirm',
            'password.confirm.store',
            'password.confirmation',
            'two-factor.*',
            'passkey.registration-options',
            'passkey.store',
            'logout',
        );
    }
}
