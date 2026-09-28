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
 * @property list<array{field_key: string, value: string}>|null $access_rules
 * @property list<array{field_key: string, value: string}>|null $auto_approve_rules
 * @property string $price_mode
 * @property int $price_cents
 * @property list<array{from_value: int, from_unit: string, unit_value: int, unit: string, price_cents: int}>|null $pricing_rules
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
            'access_rules' => 'array',
            'auto_approve_rules' => 'array',
            'pricing_rules' => 'array',
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

    /** @return list<int> ids of every resource below this one */
    public function descendantIds(): array
    {
        $resources = self::query()->get(['id', 'parent_id']);
        $ids = [];
        $frontier = [$this->id];
        while ($frontier !== []) {
            $children = $resources->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $ids = [...$ids, ...$children];
            $frontier = $children;
        }

        return array_values($ids);
    }

    /**
     * This resource with all ancestors and descendants. Booking any of them
     * blocks the others (a building contains its rooms).
     *
     * @return list<int>
     */
    public function relatedIds(): array
    {
        $resources = self::query()->get(['id', 'parent_id']);
        $ids = [$this->id];
        $parent = $this->parent_id;
        while ($parent !== null) {
            $ids[] = (int) $parent;
            $parent = $resources->firstWhere('id', $parent)?->parent_id;
        }

        return array_values(array_unique([...$ids, ...$this->descendantIds()]));
    }
}
