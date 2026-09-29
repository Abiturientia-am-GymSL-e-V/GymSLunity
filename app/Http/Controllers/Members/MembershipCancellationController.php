<?php

declare(strict_types=1);

namespace App\Http\Controllers\Members;

use App\Configuration\MailConfigurator;
use App\Http\Controllers\Controller;
use App\Mail\MembershipCancellationConfirmedMail;
use App\Members\UpdateMember;
use App\Models\ClubSetting;
use App\Models\Member;
use App\Support\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class MembershipCancellationController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('updateAny', Member::class);

        return Inertia::render('members/Cancellations', [
            'totalMembers' => fn () => Member::query()->count(),
            'today' => Clock::todayString(),
            'cancellations' => DB::table('membership_cancellations as cancellations')
                ->join('members', 'members.id', '=', 'cancellations.member_id')
                ->whereNull('cancellations.confirmed_at')
                ->whereNull('cancellations.withdrawn_at')
                ->orderBy('cancellations.requested_at')
                ->orderBy('cancellations.id')
                ->select([
                    'members.member_number', 'members.first_name', 'members.last_name',
                    'members.email', 'members.membership_type', 'members.lock_version',
                    'cancellations.requested_at',
                ])
                ->paginate(25),
        ]);
    }

    public function confirm(Request $request, Member $member, UpdateMember $update, MailConfigurator $mailConfigurator): RedirectResponse
    {
        Gate::authorize('update', $member);
        $values = $request->validate([
            'lock_version' => ['required', 'integer', 'min:0'],
            'exit_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.Clock::todayString()],
        ]);

        $result = DB::transaction(function () use ($request, $member, $update, $values): array {
            $configuration = ClubSetting::query()->whereKey(1)->sharedLock()->firstOrFail();
            $current = Member::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            $cancellation = DB::table('membership_cancellations')
                ->where('member_id', $member->id)
                ->whereNull('confirmed_at')
                ->whereNull('withdrawn_at')
                ->lockForUpdate()
                ->first();

            abort_unless($cancellation !== null, 409, 'Es liegt keine offene Kündigung vor.');
            if ($current->joined_at === null || $current->left_at !== null || $current->deceased_at !== null) {
                throw ValidationException::withMessages([
                    'cancellation' => 'Die Mitgliedschaft wurde inzwischen geändert. Bitte den Datensatz prüfen.',
                ]);
            }

            $update->handle(
                $current,
                $request->user(),
                (int) $values['lock_version'],
                ['left_at' => $values['exit_date']],
                $configuration->fields_version,
            );

            DB::table('membership_cancellations')->where('id', $cancellation->id)->update([
                'exit_date' => $values['exit_date'],
                'confirmed_at' => now(),
                'confirmed_by' => $request->user()->id,
                'confirmed_by_name' => $request->user()->name,
                'email_error' => null,
            ]);

            return [
                'id' => $cancellation->id,
                'email' => $current->email,
                'name' => trim($current->first_name.' '.$current->last_name),
                'exit_date' => $values['exit_date'],
            ];
        }, attempts: 3);

        try {
            $mailConfigurator->applyStored();
            Mail::to($result['email'])->send(new MembershipCancellationConfirmedMail($result['name'], $result['exit_date']));
            DB::table('membership_cancellations')->where('id', $result['id'])->update(['email_sent_at' => now()]);
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Kündigung bestätigt und Bestätigungsmail versendet.']);
        } catch (Throwable $exception) {
            report($exception);
            DB::table('membership_cancellations')->where('id', $result['id'])->update(['email_error' => 'Versand fehlgeschlagen']);
            Inertia::flash('toast', ['type' => 'warning', 'message' => 'Kündigung bestätigt. Die Bestätigungsmail konnte jedoch nicht versendet werden.']);
        }

        return back();
    }
}
