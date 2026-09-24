<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $member_id
 * @property int $balance_cents
 * @property Member $member
 */
class ContributionAccount extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['balance_cents' => 'integer'];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return HasMany<Contribution, $this> */
    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class, 'account_id');
    }

    /** @return HasMany<ContributionTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(ContributionTransaction::class, 'account_id');
    }
}
