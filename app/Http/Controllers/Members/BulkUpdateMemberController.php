<?php

namespace App\Http\Controllers\Members;

use App\Http\Controllers\Controller;
use App\Http\Requests\Members\BulkUpdateMembersRequest;
use App\Members\BulkUpdateMembers;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class BulkUpdateMemberController extends Controller
{
    public function __invoke(BulkUpdateMembersRequest $request, BulkUpdateMembers $update): RedirectResponse
    {
        $result = $update->handle(
            $request->validated('members'),
            $request->validated('values'),
            $request->user(),
            $request->integer('configuration_version'),
        );
        $message = $result['changed'].' '.($result['changed'] === 1 ? 'Mitglied wurde' : 'Mitglieder wurden').' aktualisiert.';
        if ($result['unchanged'] > 0) {
            $message .= ' Bei '.$result['unchanged'].' '.($result['unchanged'] === 1 ? 'Mitglied gab' : 'Mitgliedern gab').' es keine Änderung.';
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
