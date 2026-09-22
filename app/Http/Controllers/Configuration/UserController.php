<?php

namespace App\Http\Controllers\Configuration;

use App\Configuration\ConfigurationAudit;
use App\Configuration\UserRoles;
use App\Http\Controllers\Controller;
use App\Models\ClubSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        $q = trim($data['q'] ?? '');
        $query = User::query()->select('id', 'name', 'email', 'roles', 'is_active', 'lock_version', 'email_verified_at', 'created_at');
        if ($q !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
            $query->where(fn ($query) => $query->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("email LIKE ? ESCAPE '!'", [$pattern]));
        }

        return Inertia::render('configuration/Users', [
            'users' => $query->orderBy('name')->orderBy('id')->paginate(25)->withQueryString(), 'q' => $q,
            'roles' => UserRoles::LABELS, 'descriptions' => UserRoles::DESCRIPTIONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        return $this->save($request, $user);
    }

    private function save(Request $request, ?User $user = null): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim($request->string('email')->toString()))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users')->ignore($user)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:12', 'max:72', 'confirmed', Password::min(12)],
            'roles' => ['present', 'array', 'max:7'], 'roles.*' => ['required', 'string', 'distinct', Rule::in(array_keys(UserRoles::LABELS))],
            'is_active' => ['required', 'boolean'], 'verified' => ['required', 'boolean'],
            'lock_version' => [$user ? 'required' : 'nullable', 'integer', 'min:0'],
        ], ['required' => 'Dieses Feld ist erforderlich.', 'unique' => 'Diese E-Mail-Adresse ist bereits vergeben.', 'email' => 'Bitte eine gültige E-Mail-Adresse eingeben.', 'min' => 'Das Passwort muss mindestens 12 Zeichen enthalten.', 'confirmed' => 'Die Passwörter stimmen nicht überein.', 'in' => 'Diese Rolle ist nicht zulässig.']);

        DB::transaction(function () use ($request, $data, $user): void {
            ClubSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            abort_unless($request->user()->fresh()?->isAdministrator(), 403);
            $current = $user ? User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail() : new User;
            if ($current->exists && $current->lock_version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => 'Dieses Konto wurde inzwischen geändert. Bitte lade die Benutzerliste neu.']);
            }
            $before = $current->exists ? $this->snapshot($current) : [];
            if ($current->is($request->user()) && (! $data['is_active'] || ! $data['verified'] || ! in_array('admin', $data['roles'], true))) {
                throw ValidationException::withMessages(['roles' => 'Das eigene Administratorkonto kann hier nicht gesperrt oder herabgestuft werden.']);
            }
            $current->fill(Arr::only($data, ['name', 'email']));
            $current->forceFill(Arr::only($data, ['roles', 'is_active']));
            $current->email_verified_at = $data['verified'] ? ($current->email_verified_at ?? now()) : null;
            $passwordChanged = ! empty($data['password']);
            if ($passwordChanged) {
                $current->password = $data['password'];
            }
            $current->lock_version = ($current->lock_version ?? 0) + 1;
            if ($passwordChanged || ! $data['is_active']) {
                $current->remember_token = Str::random(60);
            }
            $current->save();
            $after = $this->snapshot($current);
            if ($passwordChanged) {
                $after['password_changed'] = true;
            }
            ConfigurationAudit::record($request->user(), 'Benutzer: '.$current->getKey(), $before, $after);
            if ($passwordChanged || ! $data['is_active']) {
                DB::table('sessions')->where('user_id', $current->getKey())->where('id', '<>', $request->session()->getId())->delete();
                DB::table('password_reset_tokens')->where('email', $current->email)->delete();
            }
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Benutzerkonto gespeichert.']);

        return to_route('configuration.users.index');
    }

    /** @return array<string, mixed> */
    private function snapshot(User $user): array
    {
        return Arr::only($user->attributesToArray(), ['name', 'email', 'roles', 'is_active', 'email_verified_at', 'lock_version']);
    }
}
