<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Passkey;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'canManagePasskeys' => Features::canManagePasskeys(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'passkeys' => $this->passkeys($request),
            'sessions' => $this->sessions($request),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $request->user()->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('settings/Security', $props);
    }

    public function setup(TwoFactorAuthenticationRequest $request): Response|RedirectResponse
    {
        if ($request->user()->hasRequiredSecondFactor()) {
            return to_route('dashboard');
        }

        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'canManagePasskeys' => Features::canManagePasskeys(),
            'passkeys' => $this->passkeys($request),
            'status' => $request->session()->get('status'),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();
            $props['twoFactorEnabled'] = $request->user()->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('settings/SecuritySetup', $props);
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $request->user()->update([
            'password' => $request->password,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Passwort geändert.']);

        return back();
    }

    public function destroySession(Request $request, string $session): RedirectResponse
    {
        abort_unless(config('session.driver') === 'database' && Schema::hasTable(config('session.table', 'sessions')), 404);
        $owned = DB::table(config('session.table', 'sessions'))
            ->where('id', $session)->where('user_id', $request->user()->getAuthIdentifier())->exists();
        abort_unless($owned, 404);

        if (hash_equals($request->session()->getId(), $session)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route('login')->with('status', 'Sitzung beendet.');
        }

        DB::table(config('session.table', 'sessions'))->where('id', $session)->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sitzung beendet.']);

        return back();
    }

    public function destroyOtherSessions(Request $request): RedirectResponse
    {
        abort_unless(config('session.driver') === 'database' && Schema::hasTable(config('session.table', 'sessions')), 404);
        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->where('id', '<>', $request->session()->getId())
            ->delete();
        $request->user()->forceFill(['remember_token' => str()->random(60)])->save();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Alle anderen Sitzungen wurden beendet.']);

        return back();
    }

    /** @return list<array{id: string, ip_address: string|null, user_agent: string, last_active_at: string, current: bool}> */
    private function sessions(Request $request): array
    {
        if (config('session.driver') !== 'database' || ! Schema::hasTable(config('session.table', 'sessions'))) {
            return [];
        }

        $rows = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->orderByDesc('last_activity')->get(['id', 'ip_address', 'user_agent', 'last_activity']);
        $sessions = [];
        foreach ($rows as $session) {
            $sessions[] = [
                'id' => (string) $session->id,
                'ip_address' => is_string($session->ip_address) ? $session->ip_address : null,
                'user_agent' => $this->browserLabel((string) $session->user_agent),
                'last_active_at' => now()->setTimestamp((int) $session->last_activity)->toIso8601String(),
                'current' => hash_equals($request->session()->getId(), (string) $session->id),
            ];
        }

        return $sessions;
    }

    /** @return list<array{id: string, name: string, last_used_at: string|null, created_at: string|null}> */
    private function passkeys(Request $request): array
    {
        if (! Features::canManagePasskeys()) {
            return [];
        }

        /** @var Collection<int, Passkey> $passkeys */
        $passkeys = $request->user()->passkeys()
            ->orderBy('name')
            ->get(['id', 'name', 'last_used_at', 'created_at']);

        return array_values($passkeys
            ->map(fn (Passkey $passkey): array => [
                'id' => (string) $passkey->getKey(),
                'name' => $passkey->name,
                'last_used_at' => $passkey->last_used_at?->toIso8601String(),
                'created_at' => $passkey->created_at?->toIso8601String(),
            ])
            ->all());
    }

    private function browserLabel(string $userAgent): string
    {
        $browser = str_contains($userAgent, 'Firefox/') ? 'Firefox' : (str_contains($userAgent, 'Edg/') ? 'Edge' : (str_contains($userAgent, 'Chrome/') ? 'Chrome' : (str_contains($userAgent, 'Safari/') ? 'Safari' : 'Unbekannter Browser')));
        $system = str_contains($userAgent, 'Windows') ? 'Windows' : (str_contains($userAgent, 'Macintosh') ? 'macOS' : (str_contains($userAgent, 'Linux') ? 'Linux' : (str_contains($userAgent, 'Android') ? 'Android' : (str_contains($userAgent, 'iPhone') ? 'iOS' : 'unbekanntes Gerät'))));

        return $browser.' · '.$system;
    }
}
