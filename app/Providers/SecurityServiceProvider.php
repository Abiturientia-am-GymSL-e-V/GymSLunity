<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\RequirePrivilegedTwoFactor;
use App\Models\User;
use App\Security\SecurityAudit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEvent;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;

class SecurityServiceProvider extends ServiceProvider
{
    public function boot(SecurityAudit $audit): void
    {
        Event::listen(Login::class, function (Login $event) use ($audit): void {
            $request = app(Request::class);
            $request->session()->put('security.authenticated_at', now()->timestamp);
            $request->session()->put('security.last_activity', now()->timestamp);
            // A successful password or passkey login is already a fresh
            // authentication. Do not immediately ask for the password again
            // while a required second factor is being enrolled.
            $request->session()->passwordConfirmed();
            // Passkeys verify the user themselves; Fortify only logs in via its
            // challenge route after a valid TOTP or recovery code.
            $request->session()->put(RequirePrivilegedTwoFactor::VERIFIED, $request->routeIs('passkey.login', 'two-factor.login.store'));
            $audit->record('login', 'success', $request, $event->user instanceof User ? $event->user : null, ['remembered' => $event->remember]);
        });
        Event::listen(Failed::class, function (Failed $event) use ($audit): void {
            $request = app(Request::class);
            $audit->record('login', 'failed', $request, $event->user instanceof User ? $event->user : null, [
                'account_hash' => $audit->fingerprint(is_string($event->credentials['email'] ?? null) ? $event->credentials['email'] : null),
            ]);
        });
        Event::listen(Lockout::class, fn (Lockout $event) => $audit->record('login', 'rate_limited', $event->request));
        Event::listen(Logout::class, fn (Logout $event) => $audit->record('logout', 'success', app(Request::class), $event->user instanceof User ? $event->user : null));

        $twoFactorEvents = [
            TwoFactorAuthenticationEnabled::class => ['two_factor_enabled', 'success'],
            TwoFactorAuthenticationConfirmed::class => ['two_factor_confirmed', 'success'],
            TwoFactorAuthenticationDisabled::class => ['two_factor_disabled', 'success'],
            ValidTwoFactorAuthenticationCodeProvided::class => ['two_factor_challenge', 'success'],
            TwoFactorAuthenticationFailed::class => ['two_factor_challenge', 'failed'],
        ];
        foreach ($twoFactorEvents as $eventClass => [$name, $outcome]) {
            Event::listen($eventClass, fn (TwoFactorAuthenticationEvent $event) => $audit->record($name, $outcome, app(Request::class), $event->user));
        }

        // A passkey confirmed after a password login, or a factor enrolled by a
        // user who had none, counts as the second factor of the current login.
        $markVerified = function (mixed $user): void {
            if ($user instanceof User && Auth::id() === $user->getKey()) {
                app(Request::class)->session()->put(RequirePrivilegedTwoFactor::VERIFIED, true);
            }
        };
        Event::listen(PasskeyVerified::class, fn (PasskeyVerified $event) => $markVerified($event->user));
        Event::listen(PasskeyRegistered::class, fn (PasskeyRegistered $event) => $markVerified($event->user));
        Event::listen(TwoFactorAuthenticationConfirmed::class, fn (TwoFactorAuthenticationConfirmed $event) => $markVerified($event->user));

        Event::listen(PasskeyRegistered::class, fn (PasskeyRegistered $event) => $audit->record('passkey_registered', 'success', app(Request::class), $event->user instanceof User ? $event->user : null, ['passkey_id' => $event->passkey->getKey()]));
        Event::listen(PasskeyVerified::class, fn (PasskeyVerified $event) => $audit->record('passkey_verified', 'success', app(Request::class), $event->user instanceof User ? $event->user : null, ['passkey_id' => $event->passkey->getKey()]));
        Event::listen(PasskeyDeleted::class, fn (PasskeyDeleted $event) => $audit->record('passkey_deleted', 'success', app(Request::class), $event->user instanceof User ? $event->user : null, ['passkey_id' => $event->passkey->getKey()]));
    }
}
