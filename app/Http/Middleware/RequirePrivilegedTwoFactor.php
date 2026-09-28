<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\FormOfAddress;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequirePrivilegedTwoFactor
{
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
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route('login')->withErrors(['email' => 'Privilegierte Konten müssen sich vollständig neu anmelden.']);
        }

        if ($user->hasRequiredSecondFactor() || $this->isSetupRoute($request)) {
            return $next($request);
        }

        return to_route('security.setup')->with('status', FormOfAddress::choose(
            'Für privilegierte Konten ist eine zusätzliche Anmeldemethode verpflichtend. Bitte richte jetzt TOTP oder einen Passkey ein.',
            'Für privilegierte Konten ist eine zusätzliche Anmeldemethode verpflichtend. Bitte richten Sie jetzt TOTP oder einen Passkey ein.',
        ));
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
