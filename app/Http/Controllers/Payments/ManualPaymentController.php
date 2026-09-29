<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\ManualPaymentRequest;
use App\Models\Contribution;
use App\Models\Member;
use App\Payments\ContributionLedger;
use App\Payments\Money;
use App\Support\IdempotencyKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ManualPaymentController extends Controller
{
    public function __invoke(ManualPaymentRequest $request, ContributionLedger $ledger): RedirectResponse
    {
        $data = $request->validated();
        $member = Member::query()->where('member_number', $data['member_number'])->firstOrFail();
        $booked = DB::transaction(function () use ($ledger, $member, $request, $data): bool {
            if (! IdempotencyKey::claim('manual_booking', $data['creation_key'])) {
                return false;
            }
            if ($data['direction'] === 'payment') {
                $ledger->payment($member, $request->user(), Money::cents($data['amount']), $data['booking_date'], $data['description'], $data['reference'] ?? null, kind: 'manual_payment');
            } else {
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
            }

            return true;
        }, attempts: 3);
        Inertia::flash('toast', match (true) {
            ! $booked => ['type' => 'info', 'message' => 'Diese Buchung wurde bereits verbucht und nicht noch einmal angelegt.'],
            $data['direction'] === 'payment' => ['type' => 'success', 'message' => 'Zahlungseingang wurde verbucht.'],
            default => ['type' => 'success', 'message' => 'Forderung wurde verbucht.'],
        });

        return back();
    }
}
