<?php

declare(strict_types=1);

namespace App\Http\Controllers\Forms;

use App\Configuration\ClubSettings;
use App\Forms\SignatureListColumns;
use App\Http\Controllers\Controller;
use App\Http\Requests\Forms\SignatureListRequest;
use App\Members\MemberFields;
use App\Members\MemberReportValue;
use App\Members\MemberReportWriter;
use App\Models\Member;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class SignatureListController extends Controller
{
    private const EXCLUDED_FILTERS = [
        'iban', 'mandate_reference', 'mandate_signed_at', 'mandate_type', 'account_holder_first_name',
        'account_holder_last_name', 'account_holder_street', 'account_holder_postal_code',
        'account_holder_city', 'account_holder_country',
    ];

    public function __construct(private readonly ClubSettings $clubSettings) {}

    public function index(): Response
    {
        $today = now()->toDateString();
        $filterFields = collect(MemberFields::directoryFields())
            ->reject(fn (array $field): bool => in_array($field['key'], self::EXCLUDED_FILTERS, true))
            ->filter(fn (array $field): bool => ! $field['custom'] || $field['filterable'])
            ->values();
        $filterKeys = $filterFields->pluck('key');
        $members = Member::query()
            ->orderBy('last_name')->orderBy('first_name')
            ->get()
            ->map(function (Member $member) use ($filterKeys, $today): array {
                $snapshot = MemberFields::snapshot($member);

                return [
                    'member_number' => $member->member_number,
                    'name' => trim(implode(' ', array_filter([$member->first_name, $member->middle_name, $member->last_name]))),
                    'email' => $member->email,
                    'mobile_phone' => $member->mobile_phone,
                    'status' => $this->status($member, $today),
                    'filter_values' => $filterKeys->mapWithKeys(
                        fn (string $key): array => [$key => $snapshot[$key] ?? null],
                    )->all(),
                ];
            });
        $columns = SignatureListColumns::fields()->values()
            ->map(fn (array $field): array => ['key' => $field['key'], 'label' => $field['label']])
            ->prepend(['key' => 'member_number', 'label' => 'Mitgliedsnummer'])
            ->push(['key' => 'signature', 'label' => 'Unterschrift'])
            ->unique('key')->values();

        return Inertia::render('forms/SignatureLists', [
            'members' => $members,
            'columns' => $columns,
            'filterFields' => $filterFields,
        ]);
    }

    public function document(SignatureListRequest $request): HttpResponse
    {
        $available = SignatureListColumns::fields();
        $data = $request->validated();
        $selected = $request->collect('member_numbers')
            ->mapWithKeys(fn (mixed $number, int $position): array => [(int) $number => $position]);
        $members = Member::query()->whereIn('member_number', $selected->keys())->get(Member::LIST_FIELDS)
            ->sortBy(fn (Member $member): int => $selected[$member->member_number])->values();
        $headers = array_map(fn (string $key): string => match ($key) {
            'member_number' => 'Mitgliedsnummer', 'signature' => 'Unterschrift', default => $available[$key]['label'],
        }, $data['columns']);
        $rows = $members->map(function (Member $member) use ($data, $available): array {
            $snapshot = [...MemberFields::snapshot($member), 'member_number' => $member->member_number];

            return array_map(fn (string $key): string => $key === 'signature' ? '' : MemberReportValue::format($snapshot[$key] ?? null, $available[$key] ?? []), $data['columns']);
        })->all();
        $printedAt = now()->setTimezone(config('app.display_timezone'));
        $html = view('forms.signature-list', [
            ...$data, 'headers' => $headers, 'rows' => $rows, 'logo' => $this->clubSettings->logoDataUri(),
            'clubName' => $this->clubSettings->text('name') ?: config('app.name'), 'printedAt' => $printedAt,
        ])->render();
        $pdf = MemberReportWriter::pdf($html, true);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Unterschriftsliste_'.now()->format('Y-m-d-His').'.pdf"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function status(Member $member, string $today): string
    {
        if ($member->joined_at !== null && $member->joined_at->toDateString() > $today) {
            return 'future';
        }
        if (($member->left_at !== null && $member->left_at->toDateString() <= $today)
            || ($member->deceased_at !== null && $member->deceased_at->toDateString() <= $today)) {
            return 'former';
        }
        if ($member->joined_at === null && $member->left_at === null && $member->deceased_at === null) {
            return 'contacts';
        }
        if ($member->joined_at !== null && $member->joined_at->toDateString() <= $today) {
            return 'active';
        }

        return 'other';
    }
}
