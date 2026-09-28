<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Security\SecurityAudit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditSecurityEvent
{
    public function __construct(private readonly SecurityAudit $audit) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $event): Response
    {
        $actor = $request->user();
        $response = $next($request);
        $this->audit->record($event, $response->getStatusCode() < 400 ? 'success' : 'failed', $request, $actor, [
            'route' => $request->route()?->getName(),
            'method' => $request->method(),
            'status' => $response->getStatusCode(),
        ]);

        return $response;
    }
}
