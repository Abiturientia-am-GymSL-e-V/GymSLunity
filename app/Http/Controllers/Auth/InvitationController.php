<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\ResetUserPassword;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Security\UserInvitations;
use App\Support\FormOfAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** First password of an account created in the administration. */
class InvitationController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        return Inertia::render('auth/AcceptInvitation', [
            'email' => (string) $request->query('email', ''),
            'token' => $token,
            'passwordRules' => PasswordRule::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(Request $request, UserInvitations $invitations, ResetUserPassword $reset): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ]);
        // Deactivated accounts cannot redeem their link.
        $status = $invitations->broker()->reset(
            [...$request->only('email', 'password', 'password_confirmation', 'token'), 'is_active' => true],
            function (User $user) use ($request, $reset): void {
                $reset->reset($user, $request->only('password', 'password_confirmation'));
                if ($user->email_verified_at === null) {
                    $user->forceFill(['email_verified_at' => now()])->save();
                }
            },
        );
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => FormOfAddress::choose(
                'Dieser Link ist ungültig oder abgelaufen. Bitte fordere über „Passwort vergessen“ oder bei der Administration einen neuen an.',
                'Dieser Link ist ungültig oder abgelaufen. Bitte fordern Sie über „Passwort vergessen“ oder bei der Administration einen neuen an.',
            )]);
        }

        return to_route('login')->with('status', FormOfAddress::choose(
            'Dein Passwort wurde festgelegt. Du kannst dich jetzt anmelden.',
            'Ihr Passwort wurde festgelegt. Sie können sich jetzt anmelden.',
        ));
    }
}
