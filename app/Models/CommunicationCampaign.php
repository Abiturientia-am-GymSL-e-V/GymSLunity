<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $kind
 * @property string|null $format
 * @property string $subject
 * @property string $body
 * @property list<array{name: string, mime: string, size: int}>|null $attachments
 * @property array<string, mixed> $filters
 * @property int $recipient_count
 * @property int $skipped_count
 * @property int $success_count
 * @property int $failure_count
 * @property string $created_by_name
 */
class CommunicationCampaign extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'attachments' => 'array',
            'recipient_count' => 'integer',
            'skipped_count' => 'integer',
            'success_count' => 'integer',
            'failure_count' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<CommunicationDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(CommunicationDelivery::class, 'campaign_id');
    }
}
