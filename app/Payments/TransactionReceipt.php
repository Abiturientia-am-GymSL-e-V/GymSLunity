<?php

declare(strict_types=1);

namespace App\Payments;

use App\Configuration\ClubSettings;
use App\Members\MemberReportWriter;
use App\Models\ContributionTransaction;

/**
 * Receipt for one contribution account entry. Account entries are
 * immutable, so the PDF is rendered on demand and never stored.
 */
final class TransactionReceipt
{
    public function __construct(private readonly ClubSettings $clubSettings, private readonly TransactionReport $report) {}

    public function pdf(ContributionTransaction $transaction): string
    {
        $transaction->loadMissing('account.member');

        return MemberReportWriter::pdf(view('payments.transaction-receipt', [
            'transaction' => $transaction,
            'member' => $transaction->account->member,
            'club' => $this->clubSettings->data(),
            'logo' => $this->clubSettings->logoDataUri(),
            'title' => $this->title($transaction),
            'kind' => $this->report->kindLabel($transaction->kind),
        ])->render());
    }

    public function filename(ContributionTransaction $transaction): string
    {
        return 'beleg-KB-'.$transaction->id.'.pdf';
    }

    /** Credits (negative amounts) are received payments, the rest are charges. */
    private function title(ContributionTransaction $transaction): string
    {
        return $transaction->amount_cents < 0 ? 'Zahlungsbestätigung' : 'Buchungsbeleg';
    }
}
