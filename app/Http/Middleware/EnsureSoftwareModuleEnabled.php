<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Configuration\SoftwareModules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSoftwareModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless(array_key_exists($module, SoftwareModules::OPTIONAL), 404);
        abort_unless(SoftwareModules::enabled($module), 404);

        return $next($request);
    }
}
