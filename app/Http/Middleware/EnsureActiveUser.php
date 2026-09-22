<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->is_active) {
            return $this->signOut($request);
        }
        $response = $next($request);
        // Also covers a disabled account finishing an earlier two-factor challenge.
        if ($request->user() && ! $request->user()->is_active) {
            return $this->signOut($request);
        }

        return $response;
    }

    private function signOut(Request $request): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login')->withErrors(['email' => 'Dieses Benutzerkonto ist deaktiviert.']);
    }
}
