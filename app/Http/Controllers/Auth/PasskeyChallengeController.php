<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequirePrivilegedTwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PasskeyChallengeController extends Controller
{
    /** Asks privileged users who signed in with their password to confirm the login with a passkey. */
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if ($request->session()->get(RequirePrivilegedTwoFactor::VERIFIED) === true || ! $request->user()->hasPasskeysEnabled()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        return Inertia::render('auth/PasskeyChallenge');
    }
}
