<?php

declare(strict_types=1);

namespace App\Http\Controllers\SelfService;

use App\Configuration\ClubSettings;
use App\Http\Controllers\Controller;
use App\Mail\SelfServiceAccessMail;
use App\Models\Member;
use App\SelfService\Access;
use App\SelfService\EmailAddressFilter;
use App\SelfService\ProfileChanges;
use App\Support\FormOfAddress;
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
    public function __construct(private readonly ClubSettings $clubSettings, private readonly EmailAddressFilter $emailFilter) {}

    public function index(): Response
    {
        return Inertia::render('selfservice/Access', ['publicJoin' => $this->clubSettings->enabled('public_join_enabled')]);
    }

    public function join(): Response
    {
        abort_unless($this->clubSettings->enabled('public_join_enabled'), 404);

        return Inertia::render('selfservice/Join');
    }

    public function request(Request $request): RedirectResponse
    {
        $values = $request->validate([
            'email' => ['nullable', 'required_without:member_number', 'email:rfc', 'max:255'], 'purpose' => ['required', Rule::in(['login', 'join', 'email'])],
            'member_number' => ['nullable', 'required_without:email', 'integer', 'min:1'],
        ]);
        $email = strtolower(trim((string) ($values['email'] ?? '')));
        $purpose = $values['purpose'];
        if ($purpose === 'email') {
            return $this->requestEmailChange($request, $email);
        }
        if ($purpose === 'join') {
            abort_unless($this->clubSettings->enabled('public_join_enabled'), 403);
        }
        if ($purpose === 'join' && $email === '') {
            throw ValidationException::withMessages(['email' => FormOfAddress::choose('Bitte gib eine E-Mail-Adresse an.', 'Bitte geben Sie eine E-Mail-Adresse an.')]);
        }
        if ($purpose === 'join') {
            $this->emailFilter->assertAllowed($email);
        }
        (new Timebox)->call(function () use ($values, $email, $purpose): void {
            $identifier = $email !== '' ? $email : 'member:'.($values['member_number'] ?? '');
            $key = 'selfservice-mail:'.hash('sha256', $identifier);
            if (RateLimiter::tooManyAttempts($key, 3)) {
                return;
            }
            RateLimiter::hit($key, 900);
            $matches = Member::query()
                ->when($email !== '', fn ($query) => $query->whereRaw('LOWER(email) = ?', [$email]))
                ->when(! empty($values['member_number']), fn ($query) => $query->where('member_number', $values['member_number']))
                ->limit(2)->get();
            if ($matches->count() > 1 || ($matches->isEmpty() && ($purpose !== 'join' || ! empty($values['member_number'])))) {
                return;
            }
            $member = $matches->first();
            if ($member?->deceased_at !== null) {
                return;
            }
            $deliveryEmail = $purpose === 'join' ? $email : strtolower(trim((string) $member->email));
            if ($deliveryEmail === '') {
                return;
            }
            $token = bin2hex(random_bytes(32));
            DB::table('selfservice_tokens')->where('expires_at', '<', now())->delete();
            DB::table('selfservice_tokens')->insert([
                'token_hash' => hash('sha256', $token), 'member_id' => $member?->id,
                'email' => $deliveryEmail, 'purpose' => $purpose, 'expires_at' => now()->addMinutes(15),
            ]);
            // Deliver after the response so SMTP latency does not disclose account existence.
            \Illuminate\Support\defer(function () use ($deliveryEmail, $token, $purpose): void {
                try {
                    Mail::to($deliveryEmail)->send(new SelfServiceAccessMail($token, $purpose));
                } catch (\Throwable $exception) {
                    DB::table('selfservice_tokens')->where('token_hash', hash('sha256', $token))->delete();
                    report($exception);
                }
            });
        }, 500000);
        Inertia::flash('toast', ['type' => 'info', 'message' => FormOfAddress::choose('Wenn ein Zugang möglich ist, erhältst du eine E-Mail. Prüfe auch deinen Spamordner. Bei gemeinsam genutzten Adressen gib bitte zusätzlich deine Mitgliedsnummer an.', 'Wenn ein Zugang möglich ist, erhalten Sie eine E-Mail. Prüfen Sie auch Ihren Spamordner. Bei gemeinsam genutzten Adressen geben Sie bitte zusätzlich Ihre Mitgliedsnummer an.')]);

        return back();
    }

    public function consume(Request $request): RedirectResponse
    {
        $request->validate(['token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']]);
        $destination = DB::transaction(function () use ($request): string {
            $token = DB::table('selfservice_tokens')->where('token_hash', hash('sha256', $request->string('token')->toString()))->lockForUpdate()->first();
            if (! $token || $token->expires_at <= now()->toDateTimeString()) {
                throw ValidationException::withMessages(['token' => FormOfAddress::choose('Dieser Link ist ungültig oder abgelaufen. Bitte fordere einen neuen an.', 'Dieser Link ist ungültig oder abgelaufen. Bitte fordern Sie einen neuen an.')]);
            }
            if ($token->purpose === 'email') {
                throw ValidationException::withMessages(['token' => FormOfAddress::choose('Bitte öffne den Bestätigungslink aus der E-Mail, um die neue Adresse zu übernehmen.', 'Bitte öffnen Sie den Bestätigungslink aus der E-Mail, um die neue Adresse zu übernehmen.')]);
            }
            $member = $token->member_id ? Member::query()->whereKey($token->member_id)->lockForUpdate()->first() : null;
            if ($token->member_id) {
                abort_unless($member && strtolower((string) $member->email) === $token->email && $member->deceased_at === null, 403);
            } else {
                abort_unless($this->clubSettings->enabled('public_join_enabled'), 403);
                if (! $this->emailFilter->allows($token->email)) {
                    throw ValidationException::withMessages(['token' => FormOfAddress::choose('Diese E-Mail-Adresse ist für den Mitgliederbereich nicht mehr zugelassen. Bitte verwende eine andere Adresse.', 'Diese E-Mail-Adresse ist für den Mitgliederbereich nicht mehr zugelassen. Bitte verwenden Sie eine andere Adresse.')]);
                }
                if (Member::query()->whereRaw('LOWER(email) = ?', [$token->email])->exists()) {
                    throw ValidationException::withMessages(['token' => FormOfAddress::choose('Bitte fordere für diese Adresse einen neuen Mitgliederzugang an.', 'Bitte fordern Sie für diese Adresse einen neuen Mitgliederzugang an.')]);
                }
            }
            DB::table('selfservice_tokens')->where('id', $token->id)->delete();
            Access::signIn($request, $token->email, $member?->id);

            return $member ? '/selfservice' : '/selfservice/beitritt';
        });

        Inertia::clearHistory();

        return redirect($destination);
    }

    public function confirmEmail(Request $request, string $token): RedirectResponse
    {
        $confirmed = DB::transaction(function () use ($token): array {
            $confirmation = DB::table('selfservice_tokens')
                ->where('token_hash', hash('sha256', $token))
                ->where('purpose', 'email')
                ->lockForUpdate()
                ->first();

            abort_unless(
                $confirmation && $confirmation->expires_at > now()->toDateTimeString(),
                410,
                FormOfAddress::choose('Dieser Bestätigungslink ist ungültig oder abgelaufen. Bitte fordere im Mitgliederportal einen neuen an.', 'Dieser Bestätigungslink ist ungültig oder abgelaufen. Bitte fordern Sie im Mitgliederportal einen neuen an.'),
            );

            $member = Member::query()->whereKey($confirmation->member_id)->lockForUpdate()->first();
            abort_unless($member && $member->deceased_at === null, 410, 'Dieser Bestätigungslink kann nicht mehr verwendet werden.');
            abort_if(
                Member::query()
                    ->whereRaw('LOWER(email) = ?', [$confirmation->email])
                    ->whereKeyNot($member->id)
                    ->exists(),
                409,
                FormOfAddress::choose('Die E-Mail-Adresse wird inzwischen bereits verwendet. Bitte fordere im Mitgliederportal einen neuen Link an.', 'Die E-Mail-Adresse wird inzwischen bereits verwendet. Bitte fordern Sie im Mitgliederportal einen neuen Link an.'),
            );

            abort_unless(
                $this->emailFilter->allows($confirmation->email),
                409,
                FormOfAddress::choose('Diese E-Mail-Adresse ist für den Mitgliederbereich nicht mehr zugelassen. Bitte fordere im Mitgliederportal einen Link für eine andere Adresse an.', 'Diese E-Mail-Adresse ist für den Mitgliederbereich nicht mehr zugelassen. Bitte fordern Sie im Mitgliederportal einen Link für eine andere Adresse an.'),
            );

            app(ProfileChanges::class)->save($member, ['email' => $confirmation->email]);
            DB::table('selfservice_tokens')->where('id', $confirmation->id)->delete();

            return ['email' => $confirmation->email, 'member_id' => $member->id];
        });

        Access::signIn($request, $confirmed['email'], $confirmed['member_id']);
        Inertia::clearHistory();
        Inertia::flash('toast', ['type' => 'success', 'message' => FormOfAddress::choose('Deine neue E-Mail-Adresse wurde bestätigt.', 'Ihre neue E-Mail-Adresse wurde bestätigt.')]);

        return redirect('/selfservice');
    }

    public function logout(Request $request): RedirectResponse
    {
        Inertia::clearHistory();
        $request->session()->forget('selfservice');
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();

        return redirect('/');
    }

    private function requestEmailChange(Request $request, string $email): RedirectResponse
    {
        $member = Access::member($request);
        if ($email === strtolower(trim((string) $member->email))) {
            throw ValidationException::withMessages(['email' => FormOfAddress::choose('Bitte gib eine andere E-Mail-Adresse ein.', 'Bitte geben Sie eine andere E-Mail-Adresse ein.')]);
        }
        $this->emailFilter->assertAllowed($email);
        if (Member::query()->whereRaw('LOWER(email) = ?', [$email])->whereKeyNot($member->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'Diese E-Mail-Adresse wird bereits verwendet.']);
        }

        $token = bin2hex(random_bytes(32));
        DB::transaction(function () use ($member, $email, $token): void {
            DB::table('selfservice_tokens')->where('member_id', $member->id)->where('purpose', 'email')->delete();
            DB::table('selfservice_tokens')->insert([
                'token_hash' => hash('sha256', $token),
                'member_id' => $member->id,
                'email' => $email,
                'purpose' => 'email',
                'expires_at' => now()->addMinutes(15),
            ]);
        });

        try {
            Mail::to($email)->send(new SelfServiceAccessMail($token, 'email'));
        } catch (\Throwable $exception) {
            DB::table('selfservice_tokens')->where('token_hash', hash('sha256', $token))->delete();
            report($exception);
            throw ValidationException::withMessages(['email' => FormOfAddress::choose('Die Bestätigungsmail konnte nicht versendet werden. Bitte versuche es später erneut.', 'Die Bestätigungsmail konnte nicht versendet werden. Bitte versuchen Sie es später erneut.')]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Die Bestätigungsmail wurde an die neue Adresse versendet.']);

        return back();
    }
}
