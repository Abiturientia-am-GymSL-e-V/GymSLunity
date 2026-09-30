<?php

declare(strict_types=1);

namespace App\Http\Controllers\Members;

use App\Http\Controllers\Controller;
use App\Members\MemberAssignments;
use App\Members\MemberNavigation;
use App\Models\Member;
use App\Models\MemberAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/** Department, office and honor assignments in the member record. */
class MemberAssignmentController extends Controller
{
    public function __construct(private readonly MemberAssignments $assignments) {}

    public function store(Request $request, Member $member): RedirectResponse
    {
        Gate::authorize('update', $member);
        $data = $request->validate(['field_key' => ['required', 'string', 'max:80']]);

        return $this->done($request, $member, 'Zuordnung gespeichert.', $this->assignments->add(
            $member, $request->user(), $this->version($request), $data['field_key'], $request->only(['option', 'starts_on', 'ends_on', 'note']),
        ));
    }

    public function update(Request $request, Member $member, MemberAssignment $assignment): RedirectResponse
    {
        $this->authorizeAssignment($member, $assignment);

        return $this->done($request, $member, 'Zuordnung korrigiert.', $this->assignments->correct(
            $member, $request->user(), $this->version($request), $assignment, $request->only(['option', 'starts_on', 'ends_on', 'note']),
        ));
    }

    public function end(Request $request, Member $member, MemberAssignment $assignment): RedirectResponse
    {
        $this->authorizeAssignment($member, $assignment);

        return $this->done($request, $member, 'Zuordnung beendet.', $this->assignments->end(
            $member, $request->user(), $this->version($request), $assignment, $request->string('ends_on')->toString(),
        ));
    }

    public function switch(Request $request, Member $member, MemberAssignment $assignment): RedirectResponse
    {
        $this->authorizeAssignment($member, $assignment);
        $note = $request->string('note')->toString();

        return $this->done($request, $member, 'Zuordnung gewechselt.', $this->assignments->switch(
            $member, $request->user(), $this->version($request), $assignment,
            $request->string('ends_on')->toString(), $request->string('option')->toString(), $note === '' ? null : $note,
        ));
    }

    public function destroy(Request $request, Member $member, MemberAssignment $assignment): RedirectResponse
    {
        $this->authorizeAssignment($member, $assignment);
        $this->assignments->delete($member, $request->user(), $this->version($request), $assignment);

        return $this->done($request, $member, 'Zuordnung gelöscht.', []);
    }

    private function authorizeAssignment(Member $member, MemberAssignment $assignment): void
    {
        Gate::authorize('update', $member);
        abort_unless($assignment->member_id === $member->getKey(), 404);
    }

    private function version(Request $request): int
    {
        return (int) $request->validate(['lock_version' => ['required', 'integer', 'min:0']])['lock_version'];
    }

    /** @param list<string> $warnings */
    private function done(Request $request, Member $member, string $message, array $warnings): RedirectResponse
    {
        Inertia::flash('toast', $warnings === []
            ? ['type' => 'success', 'message' => $message]
            : ['type' => 'warning', 'message' => $message.' Hinweis: '.implode(' ', $warnings)]);

        return to_route('members.show', ['member' => $member->member_number, 'return_to' => MemberNavigation::returnUrl($request->input('return_to'))]);
    }
}
