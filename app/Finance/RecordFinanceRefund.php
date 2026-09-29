<?php

declare(strict_types=1);

namespace App\Finance;

use App\Models\FinanceInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records that the amount of a cancellation was paid back. Only a
 * cancellation of an invoice that was already paid owes a refund.
 */
final class RecordFinanceRefund
{
    public function handle(FinanceInvoice $cancellation, User $actor, string $refundedAt, ?string $reference): FinanceInvoice
    {
        return DB::transaction(function () use ($cancellation, $actor, $refundedAt, $reference): FinanceInvoice {
            $locked = FinanceInvoice::query()->whereKey($cancellation->id)->lockForUpdate()->firstOrFail();
            if (! $locked->refundDue()) {
                throw ValidationException::withMessages(['refund' => 'Für diesen Beleg ist keine Erstattung offen.']);
            }
            $locked->update([
                'refunded_at' => $refundedAt,
                'refunded_by' => $actor->id,
                'refunded_by_name' => $actor->name,
                'refund_reference' => $reference ?: null,
            ]);

            return $locked;
        }, attempts: 3);
    }
}
