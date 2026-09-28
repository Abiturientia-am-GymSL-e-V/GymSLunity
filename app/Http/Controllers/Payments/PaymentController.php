<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Members\MemberFields;
use App\Models\ClubSetting;
use App\Models\Contribution;
use App\Models\ContributionTransaction;
use App\Models\Member;
use App\Models\MemberFieldDefinition;
use App\Payments\DunningNotices;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function __invoke(Request $request, DunningNotices $dunningNotices): Response
    {
        $tab = (string) $request->route('tab', 'overview');
        $tabs = [
            'overview' => ['Übersicht', route('payments')],
            'mandates' => ['Mandatsverwaltung', route('payments.mandates.index')],
            'create' => ['Beiträge anlegen', route('payments.create')],
            'invoices' => ['Beitragsrechnungen', route('payments.invoices.index')],
            'dunning' => ['Mahnwesen', route('payments.dunning.index')],
            'sepa' => ['SEPA-Export', route('payments.sepa.index')],
            'bank' => ['Bankimport', route('payments.bank-import.index')],
            'returns' => ['Rücklastschriften', route('payments.return-debits.index')],
            'manual' => ['Manuell buchen', route('payments.manual.index')],
        ];
        abort_unless(isset($tabs[$tab]), 404);
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = $filters['from'] ?? now()->startOfYear()->toDateString();
        $to = $filters['to'] ?? now()->endOfYear()->toDateString();

        $period = Contribution::query()->whereBetween('due_date', [$from, $to]);
        $open = (clone $period)->where('status', 'open');
        $missingMandates = $this->missingMandates();
        $settings = ClubSetting::current()->data;

        return Inertia::render('Payments', [
            'activeTab' => $tab,
            'navigationBreadcrumb' => ['title' => $tabs[$tab][0], 'href' => $tabs[$tab][1]],
            'filters' => compact('from', 'to'),
            'summary' => [
                'missing_mandates' => (clone $missingMandates)->count(),
                'open_count' => (clone $open)->count(),
                'open_cents' => (int) (clone $open)->selectRaw('COALESCE(SUM(amount_cents - paid_cents), 0) total')->value('total'),
                'contribution_count' => (clone $period)->where('kind', 'contribution')->count(),
                'contribution_cents' => (int) (clone $period)->where('kind', 'contribution')->sum('amount_cents'),
                'paid_cents' => (int) (clone $period)->sum('paid_cents'),
            ],
            'missingMandates' => fn () => $missingMandates->orderBy('last_name')->orderBy('first_name')->get()
                ->map(fn (Member $member): array => $this->memberRow($member) + ['missing' => $this->missingReasons($member)]),
            'contributions' => fn () => $this->contributions($from, $to),
            'openDebtors' => fn () => $dunningNotices->rows(),
            'transactions' => fn () => ContributionTransaction::query()
                ->with('account.member:id,member_number,first_name,last_name')
                ->whereBetween('booking_date', [$from, $to])->latest('booking_date')->latest('id')->limit(200)->get()
                ->map(fn (ContributionTransaction $entry): array => [
                    'id' => $entry->id, 'member_number' => $entry->account->member->member_number,
                    'member_name' => $entry->account->member->first_name.' '.$entry->account->member->last_name,
                    'kind' => $entry->kind, 'amount_cents' => $entry->amount_cents,
                    'booking_date' => $entry->booking_date->format('Y-m-d'), 'description' => $entry->description,
                    'reference' => $entry->reference, 'actor_name' => $entry->actor_name,
                ]),
            'members' => fn () => Member::query()->whereNull('left_at')->orderBy('last_name')->orderBy('first_name')
                ->get(['member_number', 'first_name', 'last_name', 'payment_method'])
                ->map(fn (Member $member): array => $this->memberRow($member)),
            'filterOptions' => [
                'membership_types' => Member::query()->distinct()->orderBy('membership_type')->pluck('membership_type')->filter()->values(),
                'payment_methods' => Member::query()->distinct()->orderBy('payment_method')->pluck('payment_method')->filter()->values(),
                'fields' => MemberFieldDefinition::query()->where('is_active', true)->where('filterable', true)
                    ->orderBy('position')->get()->map(fn (MemberFieldDefinition $field): array => MemberFields::descriptor($field))->values(),
            ],
            'club' => [
                'tax_deductible_enabled' => (bool) ($settings['contributions_tax_deductible'] ?? false),
                'sepa_ready' => ! empty($settings['creditor_id']) && ! empty($settings['iban']) && ! empty($settings['name']),
            ],
            'recentImports' => fn () => DB::table('payment_imports')->latest('created_at')->limit(10)->get(['id', 'original_name', 'row_count', 'imported_count', 'unmatched_count', 'created_at']),
            'unmatchedBankRows' => fn () => DB::table('payment_import_rows')
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
        ]);
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
