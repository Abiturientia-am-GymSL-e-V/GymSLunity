<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Configuration\ClubSettings;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureSelfService
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->clubSettings->enabled('selfservice_enabled'), 404);
        Inertia::encryptHistory();
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
