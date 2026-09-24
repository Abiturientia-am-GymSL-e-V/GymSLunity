<?php

namespace App\Http\Middleware;

use App\Models\ClubSetting;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureSelfService
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(ClubSetting::current()->data['selfservice_enabled'] ?? false, 404);
        Inertia::encryptHistory();
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
