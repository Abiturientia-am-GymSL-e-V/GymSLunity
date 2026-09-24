<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Payments\ContributionLedger;
use App\Payments\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        ]);
        $member = Member::query()->where('member_number', $data['member_number'])->firstOrFail();
        $ledger->payment($member, $request->user(), Money::cents($data['amount']), $data['booking_date'], $data['description'], $data['reference'] ?? null, kind: 'manual_payment');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zahlung wurde verbucht.']);

        return back();
    }
}
