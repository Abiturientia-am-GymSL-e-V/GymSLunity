<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Member;
use App\SelfService\EmailAddressFilter;
use App\Support\FormOfAddress;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locks the self-service portal while the member's stored address violates the
 * current e-mail filter. Only the portal page with the address change form,
 * the change itself, its confirmation and logging out stay available.
 */
class RequireAllowedSelfServiceEmail
{
    public function __construct(private readonly EmailAddressFilter $filter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->session()->get('selfservice.member_id');
        $member = is_int($id) ? Member::query()->find($id) : null;
        if ($member === null || ! $this->filter->requiresChange($member)) {
            return $next($request);
        }
        $message = FormOfAddress::choose(
            'Bitte ändere zuerst deine E-Mail-Adresse. Bis dahin ist der Mitgliederbereich eingeschränkt.',
            'Bitte ändern Sie zuerst Ihre E-Mail-Adresse. Bis dahin ist der Mitgliederbereich eingeschränkt.',
        );
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 423);
        }
        Inertia::flash('toast', ['type' => 'warning', 'message' => $message]);

        return redirect('/selfservice');
    }
}
