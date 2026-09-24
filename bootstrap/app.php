<?php

use App\Http\Middleware\AuditSecurityEvent;
use App\Http\Middleware\EnforceSessionInactivity;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequirePrivilegedTwoFactor;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        $middleware->trustHosts(at: function (): array {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST);

            return is_string($host) && $host !== '' ? ['^'.preg_quote($host, '/').'$'] : [];
        }, subdomains: false);

        $middleware->web(append: [
            EnsureActiveUser::class,
            EnforceSessionInactivity::class,
            RequirePrivilegedTwoFactor::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            // Bound the HTTP preload header; full preload tags remain in HTML.
            // Complex pages otherwise exceed Nginx's default header buffer.
            AddLinkHeadersForPreloadedAssets::using(6),
            SecurityHeaders::class,
        ]);

        $middleware->alias(['audit' => AuditSecurityEvent::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
