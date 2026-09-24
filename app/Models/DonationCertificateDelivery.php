<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $certificate_id
 * @property string $recipient
 * @property int|null $sent_by
 * @property string $sent_by_name
 * @property CarbonImmutable $created_at
 */
class DonationCertificateDelivery extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<DonationCertificate, $this> */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(DonationCertificate::class, 'certificate_id');
    }
}
