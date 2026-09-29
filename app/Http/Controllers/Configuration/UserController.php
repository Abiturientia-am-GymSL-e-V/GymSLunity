<?php

declare(strict_types=1);

namespace App\Http\Controllers\Configuration;

use App\Configuration\ConfigurationAudit;
use App\Configuration\UserRoles;
use App\Http\Controllers\Controller;
use App\Http\Requests\Configuration\UserRequest;
use App\Models\ClubSetting;
use App\Models\User;
use App\Security\SecurityAudit;
use App\Security\UserInvitations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(private readonly SecurityAudit $securityAudit, private readonly UserInvitations $invitations) {}

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
            'roles' => UserRoles::LABELS, 'descriptions' => UserRoles::DESCRIPTIONS, 'areas' => UserRoles::AREAS,
            'invitationHours' => UserInvitations::validHours(),
        ]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        return $this->save($request);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        return $this->save($request, $user);
    }

    /** Sends a new link to set the password, e.g. when the first one expired. */
    public function invite(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->fresh()?->isAdministrator(), 403);
        if (! $user->is_active) {
            throw ValidationException::withMessages(['invitation' => 'Gesperrte Konten erhalten keine Zugangsmail.']);
        }
        $sent = $this->invitations->send($user, $request, resent: true);
        Inertia::flash('toast', $sent
            ? ['type' => 'success', 'message' => 'Die Zugangsmail wurde erneut versendet.']
            : ['type' => 'error', 'message' => 'Die Zugangsmail konnte nicht versendet werden. Bitte die E-Mail-Konfiguration prüfen.']);

        return back();
    }

    private function save(UserRequest $request, ?User $user = null): RedirectResponse
    {
        $data = $request->validated();
        $invite = $user === null && (bool) ($data['send_invitation'] ?? false);

        $saved = DB::transaction(function () use ($request, $data, $user): User {
            ClubSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            abort_unless($request->user()->fresh()?->isAdministrator(), 403);
            $current = $user ? User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail() : new User;
            if ($current->exists && $current->lock_version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => 'Dieses Konto wurde inzwischen geändert. Bitte die Benutzerliste neu laden.']);
            }
            $before = $current->exists ? $this->snapshot($current) : [];
            if ($current->is($request->user()) && (! $data['is_active'] || ! $data['verified'] || ! in_array('admin', $data['roles'], true))) {
                throw ValidationException::withMessages(['roles' => 'Das eigene Administratorkonto kann hier nicht gesperrt oder herabgestuft werden.']);
            }
            $current->fill(Arr::only($data, ['name', 'email']));
            $current->forceFill(Arr::only($data, ['roles', 'is_active']));
            $current->email_verified_at = $data['verified'] ? ($current->email_verified_at ?? now()) : null;
            if (! $current->exists && empty($data['password'])) {
                // Nobody knows this password; the invitation link replaces it.
                $current->password = Str::password(64);
            }
            $passwordChanged = ! empty($data['password']);
            $rolesChanged = $current->exists && ($before['roles'] ?? []) !== $data['roles'];
            if ($passwordChanged) {
                $current->password = $data['password'];
            }
            $current->lock_version = ($current->lock_version ?? 0) + 1;
            if ($passwordChanged || $rolesChanged || ! $data['is_active']) {
                $current->remember_token = Str::random(60);
            }
            $current->save();
            $after = $this->snapshot($current);
            if ($passwordChanged) {
                $after['password_changed'] = true;
            }
            ConfigurationAudit::record($request->user(), 'Benutzer: '.$current->getKey(), $before, $after);
            if (($before['roles'] ?? []) !== ($after['roles'] ?? [])) {
                $this->securityAudit->record('roles_changed', 'success', $request, $request->user(), [
                    'before' => $before['roles'] ?? [],
                    'after' => $after['roles'] ?? [],
                ], User::class, $current->getKey());
            }
            if ($passwordChanged || $rolesChanged || ! $data['is_active']) {
                DB::table('sessions')->where('user_id', $current->getKey())->where('id', '<>', $request->session()->getId())->delete();
                DB::table('password_reset_tokens')->where('email', $current->email)->delete();
                $this->invitations->revoke($current);
            }

            return $current;
        });
        if ($invite && ! $this->invitations->send($saved, $request)) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => 'Benutzerkonto angelegt, die Zugangsmail konnte jedoch nicht versendet werden. Bitte die E-Mail-Konfiguration prüfen und die Mail erneut senden.']);

            return to_route('configuration.users.index');
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $invite ? 'Benutzerkonto angelegt und Zugangsmail versendet.' : 'Benutzerkonto gespeichert.']);

        return to_route('configuration.users.index');
    }

    /** @return array<string, mixed> */
    private function snapshot(User $user): array
    {
        return Arr::only($user->attributesToArray(), ['name', 'email', 'roles', 'is_active', 'email_verified_at', 'lock_version']);
    }
}
