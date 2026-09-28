<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Configuration\ClubSettings;
use App\Http\Controllers\Controller;
use App\Members\MemberFields;
use App\Models\Contribution;
use App\Models\ContributionTransaction;
use App\Models\Member;
use App\Models\MemberFieldDefinition;
use App\Payments\DunningNotices;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    public function __invoke(Request $request, DunningNotices $dunningNotices): Response
    {
        $tab = (string) $request->route('tab', 'overview');
        $tabs = [
            'overview' => ['Übersicht', route('payments'), 'Overview'],
            'mandates' => ['Mandatsverwaltung', route('payments.mandates.index'), 'Mandates'],
            'create' => ['Beiträge anlegen', route('payments.create'), 'Create'],
            'invoices' => ['Beitragsrechnungen', route('payments.invoices.index'), 'Invoices'],
            'dunning' => ['Mahnwesen', route('payments.dunning.index'), 'Dunning'],
            'sepa' => ['SEPA-Export', route('payments.sepa.index'), 'SepaExport'],
            'bank' => ['Bankimport', route('payments.bank-import.index'), 'BankImport'],
            'returns' => ['Rücklastschriften', route('payments.return-debits.index'), 'ReturnDebits'],
            'manual' => ['Manuell buchen', route('payments.manual.index'), 'ManualBooking'],
        ];
        abort_unless(isset($tabs[$tab]), 404);
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = $filters['from'] ?? now()->startOfYear()->toDateString();
        $to = $filters['to'] ?? now()->endOfYear()->toDateString();

        $pages = [
            'overview' => fn (): array => [
                'filters' => compact('from', 'to'),
                'summary' => $this->summary($from, $to),
                'transactions' => $this->transactions($from, $to),
            ],
            'mandates' => fn (): array => [
                'missingMandates' => $this->missingMandates()->orderBy('last_name')->orderBy('first_name')->get()
                    ->map(fn (Member $member): array => $this->memberRow($member) + ['missing' => $this->missingReasons($member)]),
            ],
            'create' => fn (): array => ['filterOptions' => $this->filterOptions(), 'club' => $this->club()],
            'invoices' => fn (): array => ['contributions' => $this->contributions($from, $to), 'club' => $this->club()],
            'dunning' => fn (): array => ['openDebtors' => $dunningNotices->rows()],
            'sepa' => fn (): array => ['contributions' => $this->contributions($from, $to), 'club' => $this->club()],
            'bank' => fn (): array => [
                'members' => $this->activeMembers(),
                'recentImports' => DB::table('payment_imports')->latest('created_at')->limit(10)->get(['id', 'original_name', 'row_count', 'imported_count', 'unmatched_count', 'created_at']),
                'unmatchedBankRows' => DB::table('payment_import_rows')
                    ->join('payment_imports', 'payment_imports.id', '=', 'payment_import_rows.payment_import_id')
                    ->where('payment_import_rows.status', 'unmatched')
                    ->latest('payment_import_rows.created_at')
                    ->limit(200)
                    ->get([
                        'payment_import_rows.id', 'payment_import_rows.payment_import_id', 'payment_import_rows.row_number',
                        'payment_import_rows.booking_date', 'payment_import_rows.amount_cents', 'payment_import_rows.purpose',
                        'payment_import_rows.reference', 'payment_import_rows.type', 'payment_import_rows.reason',
                        'payment_imports.original_name',
                    ]),
            ],
            'returns' => fn (): array => ['members' => $this->activeMembers()],
            'manual' => fn (): array => ['members' => $this->activeMembers()],
        ];

        return Inertia::render('payments/'.$tabs[$tab][2], [
            'navigationBreadcrumb' => ['title' => $tabs[$tab][0], 'href' => $tabs[$tab][1]],
            ...$pages[$tab](),
        ]);
    }

    /** @return array<string, int> */
    private function summary(string $from, string $to): array
    {
        $period = Contribution::query()->whereBetween('due_date', [$from, $to]);
        $open = (clone $period)->where('status', 'open');

        return [
            'missing_mandates' => $this->missingMandates()->count(),
            'open_count' => (clone $open)->count(),
            'open_cents' => (int) (clone $open)->selectRaw('COALESCE(SUM(amount_cents - paid_cents), 0) total')->value('total'),
            'contribution_count' => (clone $period)->where('kind', 'contribution')->count(),
            'contribution_cents' => (int) (clone $period)->where('kind', 'contribution')->sum('amount_cents'),
            'paid_cents' => (int) (clone $period)->sum('paid_cents'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function transactions(string $from, string $to): array
    {
        return ContributionTransaction::query()
            ->with('account.member:id,member_number,first_name,last_name')
            ->whereBetween('booking_date', [$from, $to])->latest('booking_date')->latest('id')->limit(200)->get()
            ->map(fn (ContributionTransaction $entry): array => [
                'id' => $entry->id, 'member_number' => $entry->account->member->member_number,
                'member_name' => $entry->account->member->first_name.' '.$entry->account->member->last_name,
                'kind' => $entry->kind, 'amount_cents' => $entry->amount_cents,
                'booking_date' => $entry->booking_date->format('Y-m-d'), 'description' => $entry->description,
                'reference' => $entry->reference, 'actor_name' => $entry->actor_name,
            ])->all();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function activeMembers(): Collection
    {
        return Member::query()->whereNull('left_at')->orderBy('last_name')->orderBy('first_name')
            ->get(['member_number', 'first_name', 'last_name', 'payment_method'])
            ->map(fn (Member $member): array => $this->memberRow($member));
    }

    /** @return array<string, mixed> */
    private function filterOptions(): array
    {
        return [
            'membership_types' => Member::query()->distinct()->orderBy('membership_type')->pluck('membership_type')->filter()->values(),
            'payment_methods' => Member::query()->distinct()->orderBy('payment_method')->pluck('payment_method')->filter()->values(),
            'fields' => MemberFieldDefinition::query()->where('is_active', true)->where('filterable', true)
                ->orderBy('position')->get()->map(fn (MemberFieldDefinition $field): array => MemberFields::descriptor($field))->values(),
        ];
    }

    /** @return array{tax_deductible_enabled: bool, sepa_ready: bool} */
    private function club(): array
    {
        return [
            'tax_deductible_enabled' => $this->clubSettings->enabled('contributions_tax_deductible'),
            'sepa_ready' => $this->clubSettings->sepaReady(),
        ];
    }

    /** @return Builder<Member> */
    private function missingMandates(): Builder
    {
        return Member::query()->where('payment_method', 'SEPA-Lastschrift')
            ->whereNull('left_at')
            ->where(fn (Builder $query) => $query->whereNull('iban')->orWhere('iban', '')
                ->orWhereNull('mandate_reference')->orWhere('mandate_reference', '')
                ->orWhereNull('mandate_signed_at'));
    }

    /** @return list<string> */
    private function missingReasons(Member $member): array
    {
        $missing = [];
        if (! $member->iban) {
            $missing[] = 'IBAN';
        }
        if (! $member->mandate_reference) {
            $missing[] = 'Mandatsreferenz';
        }
        if (! $member->mandate_signed_at) {
            $missing[] = 'Mandatsdatum';
        }

        return $missing;
    }

    /** @return array<string, mixed> */
    private function memberRow(Member $member): array
    {
        return [
            'member_number' => $member->member_number,
            'name' => $member->first_name.' '.$member->last_name,
            'first_name' => $member->first_name,
            'last_name' => $member->last_name,
            'email' => $member->email,
            'payment_method' => $member->payment_method,
            'iban' => $member->iban,
            'mandate_reference' => $member->mandate_reference,
            'mandate_signed_at' => $member->mandate_signed_at?->format('Y-m-d'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function contributions(string $from, string $to): array
    {
        return array_values(Contribution::query()->with('account.member:id,member_number,first_name,last_name,email,iban,mandate_reference,mandate_signed_at,mandate_type,left_at')
            ->whereBetween('due_date', [$from, $to])->latest('due_date')->latest('id')->get()
            ->map(function (Contribution $contribution): array {
                $member = $contribution->account->member;

                return [
                    'id' => $contribution->id, 'member_number' => $member->member_number,
                    'member_name' => $member->first_name.' '.$member->last_name, 'email' => $member->email,
                    'description' => $contribution->description, 'kind' => $contribution->kind,
                    'payment_reference' => $contribution->payment_reference,
                    'amount_cents' => $contribution->amount_cents, 'paid_cents' => $contribution->paid_cents,
                    'remaining_cents' => $contribution->remainingCents(), 'period_start' => $contribution->period_start->format('Y-m-d'),
                    'period_end' => $contribution->period_end->format('Y-m-d'), 'due_date' => $contribution->due_date->format('Y-m-d'),
                    'payment_method' => $contribution->payment_method, 'tax_deductible' => $contribution->tax_deductible,
                    'status' => $contribution->status, 'invoice_number' => $contribution->invoice_number,
                    'invoice_created_at' => $contribution->invoice_created_at?->toIso8601String(),
                    'invoice_sent_at' => $contribution->invoice_sent_at?->toIso8601String(),
                    'mandate_sequence' => $contribution->mandate_sequence,
                    'sepa_ready' => $contribution->status === 'open' && $contribution->payment_method === 'SEPA-Lastschrift'
                        && ! empty($member->iban) && ! empty($member->mandate_reference) && $member->mandate_signed_at !== null,
                ];
            })->all());
    }
}
