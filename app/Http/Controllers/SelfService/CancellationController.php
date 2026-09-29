<?php

declare(strict_types=1);

namespace App\Http\Controllers\SelfService;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\SelfService\Access;
use App\SelfService\PortalRules;
use App\SelfService\ProfileChanges;
use App\Support\Clock;
use App\Support\FormOfAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Members request or withdraw their own cancellation. */
class CancellationController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $member = Access::member($request);
        $values = $request->validate([
            'lock_version' => ['required', 'integer', 'min:0'],
        ]);
        DB::transaction(function () use ($member, $values): void {
            $current = Member::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->hasActiveOrUpcomingMembership() && $current->left_at === null, 403);
            PortalRules::assertUnchanged($current, (int) $values['lock_version']);
            if (DB::table('membership_cancellations')->where('member_id', $current->id)->whereNull('confirmed_at')->whereNull('withdrawn_at')->exists()) {
                throw ValidationException::withMessages(['cancellation' => FormOfAddress::choose('Deine Kündigung wurde bereits an den Vorstand übermittelt.', 'Ihre Kündigung wurde bereits an den Vorstand übermittelt.')]);
            }
            DB::table('membership_cancellations')->insert([
                'member_id' => $current->id,
                'requested_at' => now(),
            ]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => FormOfAddress::choose('Deine Kündigung wurde an den Vorstand übermittelt.', 'Ihre Kündigung wurde an den Vorstand übermittelt.')]);

        return back();
    }

    public function destroy(Request $request, ProfileChanges $changes): RedirectResponse
    {
        $member = Access::member($request);
        $values = $request->validate([
            'lock_version' => ['required', 'integer', 'min:0'],
        ]);
        DB::transaction(function () use ($member, $values, $changes): void {
            $current = Member::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            PortalRules::assertUnchanged($current, (int) $values['lock_version']);
            $cancellation = DB::table('membership_cancellations')
                ->where('member_id', $current->id)
                ->whereNull('withdrawn_at')
                ->where(function ($query): void {
                    $query->whereNull('confirmed_at')
                        ->orWhere(fn ($confirmed) => $confirmed
                            ->whereNotNull('confirmed_at')
                            ->whereDate('exit_date', '>', Clock::todayString()));
                })
                ->latest('id')
                ->lockForUpdate()
                ->first(['id', 'confirmed_at', 'exit_date']);
            if ($cancellation === null) {
                throw ValidationException::withMessages([
                    'cancellation' => 'Es liegt keine aktive Kündigung vor.',
                ]);
            }
            if ($cancellation->confirmed_at !== null) {
                abort_unless(
                    $current->left_at?->isFuture()
                    && $current->left_at->format('Y-m-d') === $cancellation->exit_date,
                    409,
                    'Das Austrittsdatum wurde inzwischen geändert.',
                );
                $changes->save($current, ['left_at' => null]);
            }
            DB::table('membership_cancellations')
                ->where('id', $cancellation->id)
                ->update(['withdrawn_at' => now()]);
        }, attempts: 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => FormOfAddress::choose('Deine Kündigung wurde zurückgenommen.', 'Ihre Kündigung wurde zurückgenommen.')]);

        return back();
    }
}
