<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Demo\DemoAccounts;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks what one visitor of the public demo could use against the others
 * or against the operator: locking the shared accounts, reading their
 * password reset links from the public mailbox, kicking other sessions,
 * backups with other visitors' input and publicly visible club content.
 */
class ProtectDemo
{
    private const BLOCKED_ROUTES = [
        // Shared accounts: credentials, second factors and other sessions.
        'password.email', 'password.update', 'user-password.update',
        'profile.update', 'profile.destroy',
        'two-factor.enable', 'two-factor.confirm', 'two-factor.disable', 'two-factor.regenerate-recovery-codes',
        'passkey.registration-options', 'passkey.store', 'passkey.destroy',
        'security.sessions.destroy-others', 'security.sessions.destroy',
        // Backups contain other visitors' input; a restore could replace the whole demo.
        'configuration.backup.*',
        // Publicly visible content of the demo site.
        'configuration.club.update', 'configuration.club.logo.*', 'configuration.public-pages.update',
    ];

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! DemoAccounts::enabled()) {
            return $next($request);
        }
        if (! $this->blocked($request)) {
            // Visitor content must not end up in search results under the demo domain.
            $response = $next($request);
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

            return $response;
        }

        $message = 'In der öffentlichen Demo ist diese Funktion gesperrt.';
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back(fallback: '/');
    }

    private function blocked(Request $request): bool
    {
        if ($request->routeIs(...self::BLOCKED_ROUTES)) {
            return true;
        }
        $target = $request->route('user');

        return $request->routeIs('configuration.users.update') && $target instanceof User && DemoAccounts::isDemoUser($target);
    }
}
