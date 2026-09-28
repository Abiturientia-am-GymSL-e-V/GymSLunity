<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Documents\SignatureImage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\ClubSetting;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'hasProfileSignature' => $request->user()->hasProfileSignature(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            ClubSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $user = User::query()->whereKey($request->user()->getKey())->lockForUpdate()->firstOrFail();
            $user->fill($request->validated());
            if ($user->isDirty('email')) {
                $this->ensureAnotherAdministrator($user, 'email');
                $user->email_verified_at = null;
            }
            $user->lock_version++;
            $user->save();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Profil gespeichert.']);

        return to_route('profile.edit');
    }

    public function storeSignature(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'signature' => [
                'required',
                'file',
                'image',
                'mimes:png,jpg,jpeg,webp',
                'max:2048',
                'dimensions:min_width=40,min_height=20,max_width=2400,max_height=1200',
            ],
        ]);

        try {
            $signature = SignatureImage::normalize($data['signature']->get());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['signature' => $exception->getMessage()]);
        }

        $request->user()->forceFill([
            'encrypted_signature' => Crypt::encryptString(base64_encode($signature)),
            'signature_mime' => 'image/png',
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Unterschrift wurde im Profil gespeichert.']);

        return to_route('profile.edit');
    }

    public function signature(Request $request): HttpResponse
    {
        $signature = $request->user()->profileSignature();
        abort_unless(is_string($signature), 404);

        return response($signature, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="unterschrift.png"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroySignature(Request $request): RedirectResponse
    {
        $request->user()->forceFill([
            'encrypted_signature' => null,
            'signature_mime' => null,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Gespeicherte Unterschrift wurde entfernt.']);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user): void {
            ClubSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $current = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureAnotherAdministrator($current, 'password');
            $current->delete();
        });
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    private function ensureAnotherAdministrator(User $user, string $field): void
    {
        if ($user->isAdministrator() && ! User::query()->whereKeyNot($user->getKey())->where('is_active', true)->whereNotNull('email_verified_at')->whereJsonContains('roles', 'admin')->exists()) {
            throw ValidationException::withMessages([$field => 'Bitte zuerst einen weiteren aktiven Administrator mit bestätigter E-Mail-Adresse anlegen.']);
        }
    }
}
