<?php

namespace App\Payments;

use App\Models\Contribution;
use App\Models\ContributionAccount;
use App\Models\ContributionTransaction;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ContributionLedger
{
    public function account(Member $member): ContributionAccount
    {
        return ContributionAccount::query()->firstOrCreate(
            ['member_id' => $member->getKey()],
            ['balance_cents' => 0],
        );
    }

    /** @param array<string, mixed>|null $metadata */
    public function charge(
        Contribution $contribution,
        User $actor,
        string $kind = 'contribution',
        ?array $metadata = null,
    ): ContributionTransaction {
        return DB::transaction(function () use ($contribution, $actor, $kind, $metadata): ContributionTransaction {
            $account = ContributionAccount::query()->whereKey($contribution->account_id)->lockForUpdate()->firstOrFail();
            $entry = ContributionTransaction::query()->create([
                'account_id' => $account->id,
                'contribution_id' => $contribution->id,
                'actor_id' => $actor->id,
                'actor_name' => $actor->name,
                'kind' => $kind,
                'amount_cents' => $contribution->amount_cents,
                'booking_date' => $contribution->due_date,
                'reference' => $contribution->invoice_number,
                'description' => $contribution->description,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);
            $availableCredit = max(0, -$account->balance_cents);
            if ($availableCredit > 0) {
                $allocated = min($availableCredit, $contribution->amount_cents);
                $contribution->update([
                    'paid_cents' => $allocated,
                    'status' => $allocated >= $contribution->amount_cents ? 'paid' : 'open',
                ]);
            }
            $account->update(['balance_cents' => $account->balance_cents + $contribution->amount_cents]);

            return $entry;
        });
    }

    /** @param array<string, mixed>|null $metadata */
    public function payment(
        Member $member,
        User $actor,
        int $amountCents,
        string $bookingDate,
        string $description,
        ?string $reference = null,
        ?Contribution $target = null,
        string $kind = 'payment',
        ?array $metadata = null,
    ): ContributionTransaction {
        return DB::transaction(function () use ($member, $actor, $amountCents, $bookingDate, $description, $reference, $target, $kind, $metadata): ContributionTransaction {
            $account = ContributionAccount::query()->where('member_id', $member->getKey())->lockForUpdate()->first();
            if (! $account) {
                $account = $this->account($member);
                $account = ContributionAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            }

            $entry = ContributionTransaction::query()->create([
                'account_id' => $account->id,
                'contribution_id' => $target?->id,
                'actor_id' => $actor->id,
                'actor_name' => $actor->name,
                'kind' => $kind,
                'amount_cents' => -$amountCents,
                'booking_date' => $bookingDate,
                'reference' => $reference,
                'description' => $description,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);
            $account->update(['balance_cents' => $account->balance_cents - $amountCents]);

            $remaining = $amountCents;
            $query = Contribution::query()->where('account_id', $account->id)->where('status', 'open');
            if ($target) {
                $query->whereKey($target->id);
            }
            foreach ($query->orderBy('due_date')->orderBy('id')->lockForUpdate()->get() as $contribution) {
                if ($remaining <= 0) {
                    break;
                }
                $allocation = min($remaining, $contribution->remainingCents());
                if ($allocation <= 0) {
                    continue;
                }
                $paid = $contribution->paid_cents + $allocation;
                $contribution->update([
                    'paid_cents' => $paid,
                    'status' => $paid >= $contribution->amount_cents ? 'paid' : 'open',
                ]);
                $remaining -= $allocation;
            }

            return $entry;
        });
    }
}
