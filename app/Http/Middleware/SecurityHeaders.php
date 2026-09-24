<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.headers.enabled', true)) {
            return $next($request);
        }

        $nonce = Vite::useCspNonce();
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        if (! $response->headers->has('Content-Security-Policy')) {
            $connect = app()->isLocal() ? "'self' http: https: ws: wss:" : "'self'";
            $scripts = app()->isLocal() ? "'self' 'nonce-{$nonce}' http://localhost:* http://127.0.0.1:*" : "'self' 'nonce-{$nonce}'";
            $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src {$scripts}; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://img.shields.io; font-src 'self' data:; connect-src {$connect}; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
        }
        if ($request->isSecure() && app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.(int) config('security.headers.hsts_max_age', 31536000).'; includeSubDomains');
        }

        return $response;
    }
}
