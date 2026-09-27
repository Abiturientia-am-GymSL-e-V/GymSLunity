<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\Member;
use App\Payments\ContributionLedger;
use App\Payments\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ManualPaymentController extends Controller
{
    public function __invoke(Request $request, ContributionLedger $ledger): RedirectResponse
    {
        $data = $request->validate([
            'member_number' => ['required', 'integer', 'exists:members,member_number'],
            'amount' => ['required', 'decimal:0,2', 'min:0.01', 'max:9999999.99'],
            'booking_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'direction' => ['required', Rule::in(['payment', 'charge'])],
        ]);
        $member = Member::query()->where('member_number', $data['member_number'])->firstOrFail();
        if ($data['direction'] === 'payment') {
            $ledger->payment($member, $request->user(), Money::cents($data['amount']), $data['booking_date'], $data['description'], $data['reference'] ?? null, kind: 'manual_payment');
        } else {
            DB::transaction(function () use ($ledger, $member, $request, $data): void {
                $account = $ledger->account($member);
                $contribution = Contribution::query()->create([
                    'account_id' => $account->id,
                    'created_by' => $request->user()->id,
                    'kind' => 'manual_charge',
                    'description' => $data['description'],
                    'amount_cents' => Money::cents($data['amount']),
                    'paid_cents' => 0,
                    'period_start' => $data['booking_date'],
                    'period_end' => $data['booking_date'],
                    'due_date' => $data['booking_date'],
                    'payment_method' => $member->payment_method,
                    'tax_deductible' => false,
                    'status' => 'open',
                ]);
                $ledger->charge($contribution, $request->user(), 'manual_charge', ['reference' => $data['reference'] ?? null]);
            }, attempts: 3);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $data['direction'] === 'payment' ? 'Zahlungseingang wurde verbucht.' : 'Forderung wurde verbucht.']);

        return back();
    }
}
