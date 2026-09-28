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
 * @property int|null $contribution_id
 * @property int|null $actor_id
 * @property string $actor_name
 * @property string $kind
 * @property int $amount_cents
 * @property CarbonImmutable $booking_date
 * @property string|null $reference
 * @property string $description
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable $created_at
 * @property ContributionAccount $account
 */
class ContributionTransaction extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'booking_date' => 'immutable_date:Y-m-d', 'metadata' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Kontobuchungen dürfen nicht verändert werden.'));
        static::deleting(fn () => throw new LogicException('Kontobuchungen dürfen nicht gelöscht werden.'));
    }

    /** @return BelongsTo<ContributionAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ContributionAccount::class, 'account_id');
    }
}
