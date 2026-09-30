<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Between the first installer step (.env written) and the second (tables
 * migrated) every page but the installer would fail on missing tables.
 * Send visitors back to the installer until the database is set up.
 */
class RedirectToInstaller
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('install.*') || is_file(storage_path('app/installed'))) {
            return $next($request);
        }

        try {
            $migrated = Schema::hasTable('club_settings');
        } catch (Throwable) {
            $migrated = false;
        }

        return $migrated ? $next($request) : redirect()->route('install.create');
    }
}
