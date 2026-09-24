<?php

namespace App\Http\Controllers\SelfService;

use App\Http\Controllers\Controller;
use App\Mail\SelfServiceAccessMail;
use App\Models\ClubSetting;
use App\Models\Member;
use App\SelfService\Access;
use App\SelfService\ProfileChanges;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Timebox;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AccessController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('selfservice/Access', ['publicJoin' => (bool) (ClubSetting::current()->data['public_join_enabled'] ?? false)]);
    }

    public function request(Request $request): RedirectResponse
    {
        $values = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'], 'purpose' => ['required', Rule::in(['login', 'join', 'email'])],
            'member_number' => ['nullable', 'integer', 'min:1'],
        ]);
        $email = strtolower(trim($values['email']));
        $purpose = $values['purpose'];
        if ($purpose === 'join') {
            abort_unless(ClubSetting::current()->data['public_join_enabled'] ?? false, 403);
        }
        $current = $purpose === 'email' ? Access::member($request) : null;
        (new Timebox)->call(function () use ($values, $email, $purpose, $current): void {
            $key = 'selfservice-mail:'.hash('sha256', $email);
            if (RateLimiter::tooManyAttempts($key, 3)) {
                return;
            }
            RateLimiter::hit($key, 900);
            $matches = Member::query()->whereRaw('LOWER(email) = ?', [$email])
                ->when($purpose !== 'email' && ! empty($values['member_number']), fn ($query) => $query->where('member_number', $values['member_number']))
                ->limit(2)->get();
            if ($purpose === 'email') {
                if ($matches->isNotEmpty()) {
                    return;
                }
                $member = $current;
            } else {
                if ($matches->count() > 1 || ($matches->isEmpty() && ($purpose !== 'join' || ! empty($values['member_number'])))) {
                    return;
                }
                $member = $matches->first();
                if ($member?->deceased_at !== null) {
                    return;
                }
            }
            $token = bin2hex(random_bytes(32));
            DB::table('selfservice_tokens')->where('expires_at', '<', now())->delete();
            DB::table('selfservice_tokens')->insert([
                'token_hash' => hash('sha256', $token), 'member_id' => $member?->id,
                'email' => $email, 'purpose' => $purpose, 'expires_at' => now()->addMinutes(15),
            ]);
            // Deliver after the response so SMTP latency does not disclose account existence.
            \Illuminate\Support\defer(function () use ($email, $token, $purpose): void {
                try {
                    Mail::to($email)->send(new SelfServiceAccessMail($token, $purpose));
                } catch (\Throwable $exception) {
                    DB::table('selfservice_tokens')->where('token_hash', hash('sha256', $token))->delete();
                    report($exception);
                }
            });
        }, 500000);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Wenn ein Zugang möglich ist, erhältst du eine E-Mail. Prüfe auch deinen Spamordner. Bei gemeinsam genutzten Adressen gib bitte zusätzlich deine Mitgliedsnummer an.']);

        return back();
    }

    public function consume(Request $request): RedirectResponse
    {
        $request->validate(['token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']]);
        $destination = DB::transaction(function () use ($request): string {
            $token = DB::table('selfservice_tokens')->where('token_hash', hash('sha256', $request->string('token')->toString()))->lockForUpdate()->first();
            if (! $token || $token->expires_at <= now()->toDateTimeString()) {
                throw ValidationException::withMessages(['token' => 'Dieser Link ist ungültig oder abgelaufen. Bitte fordere einen neuen an.']);
            }
            $member = $token->member_id ? Member::query()->whereKey($token->member_id)->lockForUpdate()->first() : null;
            if ($token->purpose === 'email') {
                $current = Access::member($request);
                abort_unless($member && $member->id === $current->id, 403);
                if (Member::query()->whereRaw('LOWER(email) = ?', [$token->email])->exists()) {
                    throw ValidationException::withMessages(['token' => 'Die Adresse kann nicht verwendet werden. Bitte wende dich an die Verwaltung.']);
                }
                app(ProfileChanges::class)->save($member, ['email' => $token->email]);
            } elseif ($token->member_id) {
                abort_unless($member && strtolower((string) $member->email) === $token->email && $member->deceased_at === null, 403);
            } else {
                abort_unless(ClubSetting::current()->data['public_join_enabled'] ?? false, 403);
                if (Member::query()->whereRaw('LOWER(email) = ?', [$token->email])->exists()) {
                    throw ValidationException::withMessages(['token' => 'Bitte fordere für diese Adresse einen neuen Mitgliederzugang an.']);
                }
            }
            DB::table('selfservice_tokens')->where('id', $token->id)->delete();
            Access::signIn($request, $token->email, $member?->id);

            return $member ? '/selfservice' : '/selfservice/beitritt';
        });

        Inertia::clearHistory();

        return redirect($destination);
    }

    public function logout(Request $request): RedirectResponse
    {
        Inertia::clearHistory();
        $request->session()->forget('selfservice');
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
