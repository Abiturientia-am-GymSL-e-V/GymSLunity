<?php

namespace App\Payments;

use App\Models\Contribution;
use App\Models\ContributionAccount;
use App\Models\ContributionTransaction;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

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
        ?ContributionAccount $lockedAccount = null,
    ): ContributionTransaction {
        return DB::transaction(function () use ($contribution, $actor, $kind, $metadata, $lockedAccount): ContributionTransaction {
            $account = $this->lockedAccount($contribution->account_id, $lockedAccount);
            $entry = ContributionTransaction::query()->create([
                'account_id' => $account->id,
                'contribution_id' => $contribution->id,
                'actor_id' => $actor->id,
                'actor_name' => $actor->name,
                'kind' => $kind,
                'amount_cents' => $contribution->amount_cents,
                'booking_date' => $contribution->due_date,
                'reference' => $contribution->payment_reference ?? $contribution->invoice_number,
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
        }, attempts: 3);
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
        ?ContributionAccount $lockedAccount = null,
    ): ContributionTransaction {
        return DB::transaction(function () use ($member, $actor, $amountCents, $bookingDate, $description, $reference, $target, $kind, $metadata, $lockedAccount): ContributionTransaction {
            $account = $lockedAccount === null
                ? ContributionAccount::query()->where('member_id', $member->getKey())->lockForUpdate()->first()
                : $this->lockedAccountForMember($member, $lockedAccount);
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
        }, attempts: 3);
    }

    /** @param array<string, mixed>|null $metadata */
    public function returnDebit(
        Member $member,
        User $actor,
        int $amountCents,
        string $bookingDate,
        string $description,
        ?string $reference = null,
        ?Contribution $target = null,
        ?array $metadata = null,
        ?ContributionAccount $lockedAccount = null,
    ): ContributionTransaction {
        return DB::transaction(function () use ($member, $actor, $amountCents, $bookingDate, $description, $reference, $target, $metadata, $lockedAccount): ContributionTransaction {
            $account = $lockedAccount === null
                ? ContributionAccount::query()->where('member_id', $member->getKey())->lockForUpdate()->first()
                : $this->lockedAccountForMember($member, $lockedAccount);
            if (! $account) {
                $account = $this->account($member);
                $account = ContributionAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            }

            $entry = ContributionTransaction::query()->create([
                'account_id' => $account->id,
                'contribution_id' => $target?->id,
                'actor_id' => $actor->id,
                'actor_name' => $actor->name,
                'kind' => 'bank_return_debit',
                'amount_cents' => $amountCents,
                'booking_date' => $bookingDate,
                'reference' => $reference,
                'description' => $description,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);
            $account->update(['balance_cents' => $account->balance_cents + $amountCents]);

            $remaining = $amountCents;
            $query = Contribution::query()->where('account_id', $account->id)->where('paid_cents', '>', 0);
            if ($target) {
                $query->whereKey($target->id);
            }
            foreach ($query->latest('due_date')->latest('id')->lockForUpdate()->get() as $contribution) {
                if ($remaining <= 0) {
                    break;
                }
                $reversal = min($remaining, $contribution->paid_cents);
                if ($reversal <= 0) {
                    continue;
                }
                $contribution->update([
                    'paid_cents' => $contribution->paid_cents - $reversal,
                    'status' => 'open',
                ]);
                $remaining -= $reversal;
            }

            return $entry;
        }, attempts: 3);
    }

    private function lockedAccount(int $accountId, ?ContributionAccount $account): ContributionAccount
    {
        if ($account === null) {
            return ContributionAccount::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
        }
        if ($account->getKey() !== $accountId) {
            throw new LogicException('Das gesperrte Beitragskonto passt nicht zum Beitrag.');
        }

        return $account;
    }

    private function lockedAccountForMember(Member $member, ContributionAccount $account): ContributionAccount
    {
        if ($account->member_id !== $member->getKey()) {
            throw new LogicException('Das gesperrte Beitragskonto passt nicht zum Mitglied.');
        }

        return $account;
    }
}
