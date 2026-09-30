<?php

declare(strict_types=1);

namespace App\Http\Controllers\Members;

use App\Http\Controllers\Controller;
use App\Http\Requests\Members\BulkAssignMembersRequest;
use App\Members\BulkAssignments;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/** Adds or ends one assignment for the members selected in a list. */
class BulkAssignMemberController extends Controller
{
    public function __invoke(BulkAssignMembersRequest $request, BulkAssignments $assignments): RedirectResponse
    {
        /** @var list<int> $members */
        $members = array_map(intval(...), $request->validated('members'));
        $version = $request->integer('configuration_version');
        $field = $request->string('field')->toString();
        $result = $request->validated('action') === 'add'
            ? $assignments->add($members, $request->user(), $version, $field, [
                'option' => (string) $request->validated('option'), 'starts_on' => $request->validated('starts_on'),
                'ends_on' => $request->validated('ends_on'), 'note' => $request->validated('note'),
            ])
            : $assignments->end($members, $request->user(), $version, $field, $request->validated('option'), (string) $request->validated('ends_on'));

        $message = $result['changed'].' '.($result['changed'] === 1 ? 'Mitglied wurde' : 'Mitglieder wurden').' aktualisiert.';
        if ($result['unchanged'] > 0) {
            $message .= ' Bei '.$result['unchanged'].' '.($result['unchanged'] === 1 ? 'Mitglied gab' : 'Mitgliedern gab').' es keine passende laufende Zuordnung.';
        }
        Inertia::flash('toast', $result['warnings'] === []
            ? ['type' => 'success', 'message' => $message]
            : ['type' => 'warning', 'message' => $message.' Hinweis: '.implode(' ', $result['warnings'])]);

        return back();
    }
}
