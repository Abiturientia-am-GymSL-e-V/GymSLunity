<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $parent_id
 * @property int|null $inventory_item_id
 * @property string $name
 * @property string|null $description
 * @property string|null $location
 * @property list<string>|null $allowed_membership_types
 * @property list<string>|null $auto_approve_membership_types
 * @property string $price_mode
 * @property int $price_cents
 * @property bool $is_active
 */
class BookingResource extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'allowed_membership_types' => 'array',
            'auto_approve_membership_types' => 'array',
            'price_cents' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<BookingResource, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<BookingResource, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<ResourceBooking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(ResourceBooking::class, 'resource_id');
    }
}
