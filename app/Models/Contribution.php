<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $account_id
 * @property int|null $batch_id
 * @property int|null $created_by
 * @property string $kind
 * @property string $description
 * @property string|null $payment_reference
 * @property int $amount_cents
 * @property int $paid_cents
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CarbonImmutable $due_date
 * @property string|null $payment_method
 * @property string|null $mandate_reference
 * @property string|null $mandate_sequence
 * @property CarbonImmutable|null $sepa_exported_at
 * @property bool $tax_deductible
 * @property string $status
 * @property string|null $invoice_number
 * @property CarbonImmutable|null $invoice_created_at
 * @property CarbonImmutable|null $invoice_sent_at
 * @property ContributionAccount $account
 * @property ContributionBatch|null $batch
 */
class Contribution extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer', 'paid_cents' => 'integer', 'tax_deductible' => 'boolean',
            'period_start' => 'immutable_date:Y-m-d', 'period_end' => 'immutable_date:Y-m-d',
            'due_date' => 'immutable_date:Y-m-d', 'invoice_created_at' => 'immutable_datetime',
            'invoice_sent_at' => 'immutable_datetime', 'sepa_exported_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Contribution $contribution): void {
            if ($contribution->payment_reference !== null) {
                return;
            }
            $account = $contribution->relationLoaded('account')
                ? $contribution->getRelation('account')
                : $contribution->account()->with('member:id,member_number')->firstOrFail();
            if (! $account instanceof ContributionAccount) {
                throw new LogicException('Beitragskonto konnte nicht ermittelt werden.');
            }
            $member = $account->relationLoaded('member')
                ? $account->getRelation('member')
                : $account->member()->firstOrFail(['id', 'member_number']);
            if (! $member instanceof Member) {
                throw new LogicException('Mitglied konnte nicht ermittelt werden.');
            }
            $memberNumber = $member->member_number;
            $contribution->forceFill(['payment_reference' => 'GYMSL-'.$memberNumber.'-'.$contribution->id])->saveQuietly();
        });
    }

    /** @return BelongsTo<ContributionAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ContributionAccount::class, 'account_id');
    }

    /** @return BelongsTo<ContributionBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ContributionBatch::class, 'batch_id');
    }

    public function remainingCents(): int
    {
        return max(0, $this->amount_cents - $this->paid_cents);
    }
}
