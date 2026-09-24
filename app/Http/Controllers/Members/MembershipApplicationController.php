<?php

namespace App\Http\Controllers\Members;

use App\Http\Controllers\Controller;
use App\Members\UpdateMember;
use App\Models\ClubSetting;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MembershipApplicationController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('updateAny', Member::class);

        return Inertia::render('members/Applications', [
            'applications' => DB::table('membership_applications as applications')
                ->join('members', 'members.id', '=', 'applications.member_id')
                ->whereNull('applications.approved_at')->orderBy('applications.submitted_at')->orderBy('applications.id')
                ->select(['members.member_number', 'members.first_name', 'members.last_name', 'members.lock_version', 'applications.membership_type', 'applications.submitted_at'])
                ->paginate(25),
        ]);
    }

    public function approve(Request $request, Member $member, UpdateMember $update): RedirectResponse
    {
        Gate::authorize('update', $member);
        $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);
        DB::transaction(function () use ($request, $member, $update): void {
            $configuration = ClubSetting::query()->whereKey(1)->sharedLock()->firstOrFail();
            $current = Member::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            $application = DB::table('membership_applications')->where('member_id', $member->id)->lockForUpdate()->first();
            abort_unless($application && $application->approved_at === null, 409, 'Es liegt kein offener Antrag vor.');
            if ($current->membership_type !== 'Kontakt' || $current->joined_at !== null || $current->left_at !== null || $current->deceased_at !== null) {
                throw ValidationException::withMessages(['application' => 'Die Mitgliedschaft wurde inzwischen geändert. Bitte prüfe den Datensatz vor der Freigabe.']);
            }
            $update->handle($current, $request->user(), $request->integer('lock_version'), [
                'membership_type' => $application->membership_type, 'joined_at' => now()->toDateString(),
            ], $configuration->fields_version);
            DB::table('membership_applications')->where('id', $application->id)->update([
                'approved_at' => now(), 'approved_by' => $request->user()->id, 'approved_by_name' => $request->user()->name,
            ]);
        }, attempts: 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Beitritt freigegeben. Die Mitgliedschaft beginnt heute.']);

        return back();
    }
}
