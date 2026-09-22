<?php

namespace App\Http\Controllers\Members;

use App\Http\Controllers\Controller;
use App\Http\Requests\Members\UpdateMemberRequest;
use App\Members\MemberFields;
use App\Members\MemberNavigation;
use App\Members\UpdateMember;
use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\MemberChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    public function show(Request $request, Member $member): Response
    {
        Gate::authorize('view', $member);
        $request->validate(['history_page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);
        $returnTo = MemberNavigation::returnUrl($request->query('return_to'));

        return Inertia::render('members/Show', [
            'member' => [...Arr::only($member->attributesToArray(), ['id', 'member_number', 'lock_version', 'created_at', 'updated_at']), ...MemberFields::snapshot($member)],
            'sections' => MemberFields::sections($member),
            'configurationVersion' => ClubSetting::current()->fields_version,
            'canEdit' => $request->user()?->can('update', $member) ?? false,
            'returnTo' => $returnTo,
            'documents' => fn () => DB::table('member_documents')->where('member_id', $member->getKey())
                ->whereIn('kind', ['application', 'sepa'])->get(['id', 'kind', 'submitted_online', 'created_at'])
                ->map(fn (object $document): array => [
                    'kind' => $document->kind, 'submitted_online' => (bool) $document->submitted_online,
                    'created_at' => $document->created_at,
                    'url' => route('members.document', ['member' => $member->member_number, 'kind' => $document->kind]),
                ]),
            'history' => fn () => MemberChange::query()->where('member_id', $member->getKey())
                ->orderByDesc('version')->paginate(10, ['id', 'actor_name', 'version', 'before', 'after', 'changed_fields', 'field_schema', 'created_at'], 'history_page')
                ->appends(['return_to' => $returnTo]),
        ]);
    }

    public function update(UpdateMemberRequest $request, Member $member, UpdateMember $update): RedirectResponse
    {
        $changed = $update->handle($member, $request->user(), $request->integer('lock_version'), $request->safe()->only(MemberFields::writable()), $request->has('configuration_version') ? $request->integer('configuration_version') : null);
        Inertia::flash('toast', ['type' => 'success', 'message' => $changed ? 'Mitgliedsdaten gespeichert.' : 'Keine Änderungen zu speichern.']);
        $returnTo = MemberNavigation::returnUrl($request->input('return_to'));

        return $request->boolean('close')
            ? redirect($returnTo)
            : to_route('members.show', ['member' => $member->member_number, 'return_to' => $returnTo]);
    }

    public function document(Member $member, string $kind): HttpResponse
    {
        Gate::authorize('view', $member);
        abort_unless(in_array($kind, ['application', 'sepa'], true), 404);
        $document = DB::table('member_documents')->where('member_id', $member->getKey())->where('kind', $kind)->first(['contents']);
        abort_unless($document !== null, 404);
        $contents = is_resource($document->contents) ? stream_get_contents($document->contents) : $document->contents;
        abort_unless(is_string($contents) && str_starts_with($contents, '%PDF-'), 422, 'Das hinterlegte Dokument ist keine lesbare PDF-Datei.');

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$kind.'-'.$member->member_number.'.pdf"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
