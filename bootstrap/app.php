<?php

declare(strict_types=1);

use App\Http\Middleware\AuditSecurityEvent;
use App\Http\Middleware\EnforceSessionInactivity;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureSoftwareModuleEnabled;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ProtectDemo;
use App\Http\Middleware\RedirectToInstaller;
use App\Http\Middleware\RequirePrivilegedTwoFactor;
use App\Http\Middleware\RequireRecentPassword;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

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

        $middleware->web(prepend: [RedirectToInstaller::class], append: [
            EnsureActiveUser::class,
            ProtectDemo::class,
            EnforceSessionInactivity::class,
            RequirePrivilegedTwoFactor::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            // Bound the HTTP preload header; full preload tags remain in HTML.
            // Complex pages otherwise exceed Nginx's default header buffer.
            AddLinkHeadersForPreloadedAssets::using(6),
            SecurityHeaders::class,
        ]);

        $middleware->alias(['audit' => AuditSecurityEvent::class, 'module' => EnsureSoftwareModuleEnabled::class, 'reconfirm' => RequireRecentPassword::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A failing mail server is an operational problem, not a bug: keep the
        // log entry, but tell the user what happened instead of showing a 500.
        $exceptions->render(function (TransportExceptionInterface $exception, Request $request) {
            $message = $exception->getCode() === 535
                ? 'Die E-Mail konnte nicht versendet werden: Der Mailserver hat die Anmeldung abgelehnt. Bitte die Zugangsdaten in den Mail-Einstellungen prüfen.'
                : 'Die E-Mail konnte nicht versendet werden, weil der Mailserver nicht erreichbar war oder die Nachricht abgelehnt hat. Bitte später erneut versuchen oder die Mail-Einstellungen prüfen.';
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $message], 503);
            }
            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

            return back(fallback: '/');
        });
    })->create();
