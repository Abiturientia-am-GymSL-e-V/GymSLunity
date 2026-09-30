<?php

declare(strict_types=1);

namespace App\Http\Controllers\Members;

use App\Configuration\ClubSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Members\StoreMemberDocumentRequest;
use App\Http\Requests\Members\StoreMemberRequest;
use App\Http\Requests\Members\UpdateMemberRequest;
use App\Members\CreateMember;
use App\Members\MemberFields;
use App\Members\MemberMandates;
use App\Members\MemberNavigation;
use App\Members\UpdateMember;
use App\Members\WelcomeMails;
use App\Models\ContributionAccount;
use App\Models\Member;
use App\Models\MemberChange;
use App\Security\MemberDocumentStore;
use App\SelfService\EmailAddressFilter;
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
    public function __construct(private readonly ClubSettings $clubSettings) {}

    public function create(Request $request): Response
    {
        Gate::authorize('create', Member::class);

        return Inertia::render('members/Create', [
            'totalMembers' => fn () => Member::query()->count(),
            'sections' => MemberFields::sections(),
            'configurationVersion' => $this->clubSettings->fieldsVersion(),
            'suggestedMemberNumber' => ((int) Member::query()->max('member_number')) + 1,
        ]);
    }

    public function store(StoreMemberRequest $request, CreateMember $create, WelcomeMails $welcomeMails): RedirectResponse
    {
        $member = $create->handle(
            $request->user(),
            $request->integer('member_number'),
            $request->safe()->only(MemberFields::writable()),
            $request->integer('configuration_version'),
            ['application' => $request->file('application_file'), 'sepa' => $request->file('sepa_file')],
        );
        $welcomeMails->sendAutomaticallyLater($member);
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
            'assignmentFields' => MemberFields::assignmentFields($member),
            'assignments' => MemberFields::assignments($member),
            'configurationVersion' => $this->clubSettings->fieldsVersion(),
            'canEdit' => $request->user()?->can('update', $member) ?? false,
            'emailFilterViolation' => app(EmailAddressFilter::class)->requiresChange($member),
            'welcomeMail' => fn () => [
                'available' => app(WelcomeMails::class)->available(),
                'last_sent' => WelcomeMails::lastSent($member),
            ],
            'returnTo' => $returnTo,
            'documents' => fn () => DB::table('member_documents')->where('member_id', $member->getKey())
                ->where('kind', 'application')->get(['id', 'kind', 'submitted_online', 'created_at'])
                ->map(fn (object $document): array => [
                    'kind' => $document->kind, 'submitted_online' => (bool) $document->submitted_online,
                    'created_at' => $document->created_at,
                    'url' => route('members.document', ['member' => $member->member_number, 'kind' => $document->kind]),
                ]),
            'mandates' => fn () => DB::table('member_documents')
                ->where('member_id', $member->getKey())
                ->where('kind', 'sepa')
                ->latest('id')
                ->get(['id', 'submitted_online', 'mandate_reference', 'mandate_signed_at', 'revoked_at', 'revocation_reason', 'created_at'])
                ->map(fn (object $mandate): array => [
                    'id' => $mandate->id,
                    'submitted_online' => (bool) $mandate->submitted_online,
                    'mandate_reference' => $mandate->mandate_reference,
                    'mandate_signed_at' => $mandate->mandate_signed_at,
                    'revoked_at' => $mandate->revoked_at,
                    'revocation_reason' => $mandate->revocation_reason,
                    'active' => $mandate->revoked_at === null
                        && $member->payment_method === 'SEPA-Lastschrift'
                        && is_string($mandate->mandate_reference)
                        && $mandate->mandate_reference !== ''
                        && $mandate->mandate_reference === $member->mandate_reference,
                    'created_at' => $mandate->created_at,
                    'url' => route('members.mandates.document', [
                        'member' => $member->member_number,
                        'document' => $mandate->id,
                    ]),
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
        $document = DB::table('member_documents')->where('member_id', $member->getKey())->where('kind', $kind)->latest('id')->first(['contents', 'encrypted', 'content_sha256']);
        abort_unless($document !== null, 404);

        return $this->documentResponse($member, $kind, $document, $documents);
    }

    public function mandateDocument(Member $member, int $document, MemberDocumentStore $documents): HttpResponse
    {
        Gate::authorize('view', $member);
        $mandate = DB::table('member_documents')
            ->where('id', $document)
            ->where('member_id', $member->getKey())
            ->where('kind', 'sepa')
            ->first(['id', 'mandate_reference', 'contents', 'encrypted', 'content_sha256']);
        abort_unless($mandate !== null, 404);

        return $this->documentResponse(
            $member,
            'sepa-'.($mandate->mandate_reference ?: $mandate->id),
            $mandate,
            $documents,
        );
    }

    private function documentResponse(Member $member, string $filename, object $document, MemberDocumentStore $documents): HttpResponse
    {
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
            'Content-Disposition' => 'attachment; filename="'.$filename.'-'.$member->member_number.'.pdf"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function storeDocument(StoreMemberDocumentRequest $request, Member $member, string $kind, MemberDocumentStore $documents, MemberMandates $mandates): RedirectResponse
    {
        abort_unless(in_array($kind, ['application', 'sepa'], true), 404);
        $file = $request->file('document');
        $contents = $documents->uploadedPdf($file);
        DB::transaction(function () use ($member, $kind, $contents, $documents, $mandates): void {
            $current = Member::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();
            if ($kind === 'sepa') {
                $mandates->revoke($current, 'Durch ein manuell hinterlegtes SEPA-Mandat ersetzt');
            }
            $documentId = $documents->store($current->getKey(), $kind, $contents, false, $kind === 'sepa' ? [
                'mandate_reference' => $current->mandate_reference,
                'mandate_signed_at' => $current->mandate_signed_at?->format('Y-m-d'),
            ] : []);
            if ($kind === 'sepa' && ($current->payment_method !== 'SEPA-Lastschrift' || ! $current->mandate_reference)) {
                DB::table('member_documents')->where('id', $documentId)->update([
                    'revoked_at' => now(),
                    'revocation_reason' => 'Manuell hinterlegt; derzeit kein aktives SEPA-Mandat',
                ]);
            }
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => $kind === 'application' ? 'Schriftlicher Antrag gespeichert.' : 'SEPA-Mandat gespeichert.']);

        return back();
    }
}
