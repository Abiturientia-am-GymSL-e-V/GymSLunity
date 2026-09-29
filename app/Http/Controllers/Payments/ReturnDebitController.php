<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\LedgerBookingRequest;
use App\Models\Contribution;
use App\Models\Member;
use App\Payments\ContributionLedger;
use App\Payments\Money;
use App\Support\IdempotencyKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ReturnDebitController extends Controller
{
    public function __invoke(LedgerBookingRequest $request, ContributionLedger $ledger): RedirectResponse
    {
        $data = $request->validated();
        $member = Member::query()->where('member_number', $data['member_number'])->firstOrFail();
        $booked = DB::transaction(function () use ($ledger, $member, $request, $data): bool {
            if (! IdempotencyKey::claim('return_debit_fee', $data['creation_key'])) {
                return false;
            }
            $account = $ledger->account($member);
            $contribution = Contribution::query()->create([
                'account_id' => $account->id, 'created_by' => $request->user()->id, 'kind' => 'return_debit_fee',
                'description' => $data['description'], 'amount_cents' => Money::cents($data['amount']), 'paid_cents' => 0,
                'period_start' => $data['booking_date'], 'period_end' => $data['booking_date'], 'due_date' => $data['booking_date'],
                'payment_method' => $member->payment_method, 'tax_deductible' => false, 'status' => 'open',
            ]);
            $ledger->charge($contribution, $request->user(), 'return_debit_fee', ['reference' => $data['reference'] ?? null]);

            return true;
        }, attempts: 3);
        Inertia::flash('toast', $booked
            ? ['type' => 'success', 'message' => 'Rücklastschriftgebühr wurde belastet.']
            : ['type' => 'info', 'message' => 'Diese Gebühr wurde bereits belastet und nicht noch einmal angelegt.']);

        return back();
    }
}
