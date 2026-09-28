<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $inventory_item_id
 * @property string $original_name
 * @property string $content_sha256
 * @property string $contents
 * @property int|null $uploaded_by
 * @property string $uploaded_by_name
 */
class InventoryDocument extends Model
{
    protected $guarded = ['id'];

    /** @return BelongsTo<InventoryItem, $this> */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
