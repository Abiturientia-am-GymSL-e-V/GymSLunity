<?php

namespace App\Http\Controllers\Members;

use App\Http\Controllers\Controller;
use App\Http\Requests\Members\StoreMemberDocumentRequest;
use App\Http\Requests\Members\StoreMemberRequest;
use App\Http\Requests\Members\UpdateMemberRequest;
use App\Members\CreateMember;
use App\Members\MemberFields;
use App\Members\MemberNavigation;
use App\Members\UpdateMember;
use App\Models\ClubSetting;
use App\Models\ContributionAccount;
use App\Models\Member;
use App\Models\MemberChange;
use App\Security\MemberDocumentStore;
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
    public function create(Request $request): Response
    {
        Gate::authorize('create', Member::class);

        return Inertia::render('members/Create', [
            'sections' => MemberFields::sections(),
            'configurationVersion' => ClubSetting::current()->fields_version,
            'suggestedMemberNumber' => ((int) Member::query()->max('member_number')) + 1,
        ]);
    }

    public function store(StoreMemberRequest $request, CreateMember $create): RedirectResponse
    {
        $member = $create->handle(
            $request->user(),
            $request->integer('member_number'),
            $request->safe()->only(MemberFields::writable()),
            $request->integer('configuration_version'),
            ['application' => $request->file('application_file'), 'sepa' => $request->file('sepa_file')],
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Mitglied wurde angelegt.']);

        return to_route('members.show', ['member' => $member->member_number]);
    }

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
            'contributionAccount' => fn () => $this->contributionAccount($member),
        ]);
    }

    /** @return array<string, mixed> */
    private function contributionAccount(Member $member): array
    {
        $account = ContributionAccount::query()->firstOrCreate(['member_id' => $member->getKey()], ['balance_cents' => 0]);

        return [
            'balance_cents' => $account->balance_cents,
            'transactions' => $account->transactions()->latest('booking_date')->latest('id')->limit(25)->get()
                ->map(fn ($entry): array => [
                    'id' => $entry->id, 'kind' => $entry->kind, 'amount_cents' => $entry->amount_cents,
                    'booking_date' => $entry->booking_date->format('Y-m-d'), 'description' => $entry->description,
                    'reference' => $entry->reference, 'actor_name' => $entry->actor_name,
                ]),
        ];
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

    public function document(Member $member, string $kind, MemberDocumentStore $documents): HttpResponse
    {
        Gate::authorize('view', $member);
        abort_unless(in_array($kind, ['application', 'sepa'], true), 404);
        $document = DB::table('member_documents')->where('member_id', $member->getKey())->where('kind', $kind)->first(['contents', 'encrypted', 'content_sha256']);
        abort_unless($document !== null, 404);
        try {
            $record = (array) $document;
            $contents = $documents->read(
                $record['contents'] ?? null,
                (bool) ($record['encrypted'] ?? false),
                is_string($record['content_sha256'] ?? null) ? $record['content_sha256'] : null,
            );
        } catch (\Throwable) {
            abort(422, 'Das hinterlegte Dokument ist beschädigt oder nicht lesbar.');
        }

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$kind.'-'.$member->member_number.'.pdf"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function storeDocument(StoreMemberDocumentRequest $request, Member $member, string $kind, MemberDocumentStore $documents): RedirectResponse
    {
        abort_unless(in_array($kind, ['application', 'sepa'], true), 404);
        $file = $request->file('document');
        $contents = $documents->uploadedPdf($file);
        $documents->store($member->getKey(), $kind, $contents, false);
        Inertia::flash('toast', ['type' => 'success', 'message' => $kind === 'application' ? 'Schriftlicher Antrag gespeichert.' : 'SEPA-Mandat gespeichert.']);

        return back();
    }
}
