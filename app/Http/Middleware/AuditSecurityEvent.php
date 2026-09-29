<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Security\SecurityAudit;
use Closure;
use Illuminate\Database\Eloquent\Model;
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
        [$subjectType, $subjectId] = $this->subject($request);
        $this->audit->record($event, $response->getStatusCode() < 400 ? 'success' : 'failed', $request, $actor, [
            'route' => $request->route()?->getName(),
            'method' => $request->method(),
            'status' => $response->getStatusCode(),
        ], $subjectType, $subjectId);

        return $response;
    }

    /**
     * The first model bound in the route, identified by the key used in the
     * URL (e.g. the member number instead of the internal id).
     *
     * @return array{0: string|null, 1: string|int|null}
     */
    private function subject(Request $request): array
    {
        $route = $request->route();
        foreach ($route?->parameters() ?? [] as $name => $value) {
            if ($value instanceof Model) {
                $field = $route?->bindingFieldFor($name) ?? $value->getRouteKeyName();
                $key = $value->getAttribute($field);

                return [class_basename($value), is_int($key) || is_string($key) ? $key : null];
            }
        }

        return [null, null];
    }
}
