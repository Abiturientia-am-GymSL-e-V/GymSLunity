<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $resource_id
 * @property int|null $member_id
 * @property string|null $requester_name
 * @property string $title
 * @property string|null $notes
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string|null $series_id
 * @property int $occurrence
 * @property string $status
 * @property int $price_cents
 * @property int|null $charge_transaction_id
 * @property int|null $refund_transaction_id
 * @property string|null $created_by_name
 * @property string|null $decided_by_name
 * @property BookingResource $resource
 * @property Member|null $member
 */
class ResourceBooking extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'price_cents' => 'integer',
            'occurrence' => 'integer',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<BookingResource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(BookingResource::class, 'resource_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
