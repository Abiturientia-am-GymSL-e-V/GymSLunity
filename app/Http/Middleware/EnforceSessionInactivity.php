<?php

namespace App\Http\Middleware;

use App\Support\FormOfAddress;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceSessionInactivity
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return $next($request);
        }

        $now = now()->getTimestamp();
        $lastActivity = (int) $request->session()->get('security.last_activity', $now);
        $timeout = max(60, (int) config('security.inactivity_timeout', 1800));
        if (($now - $lastActivity) > $timeout) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route('login')->withErrors(['email' => FormOfAddress::choose('Deine Sitzung wurde wegen Inaktivität beendet.', 'Ihre Sitzung wurde wegen Inaktivität beendet.')]);
        }

        $request->session()->put('security.last_activity', $now);

        return $next($request);
    }
}
