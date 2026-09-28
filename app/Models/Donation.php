<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $receipt_number
 * @property int|null $created_by
 * @property string $created_by_name
 * @property string $donor_name
 * @property string $donor_street
 * @property string $donor_postal_code
 * @property string $donor_city
 * @property string $donor_country
 * @property string|null $donor_email
 * @property string $donation_type
 * @property int $amount_cents
 * @property CarbonImmutable $donated_at
 * @property string $purpose_code
 * @property string $purpose_label
 * @property string|null $description
 * @property string|null $asset_origin
 * @property string|null $valuation_document_reference
 * @property bool $expense_waiver
 * @property CarbonImmutable $created_at
 * @property DonationCertificate|null $certificate
 */
class Donation extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'donated_at' => 'immutable_date:Y-m-d',
            'expense_waiver' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasOne<DonationCertificate, $this> */
    public function certificate(): HasOne
    {
        return $this->hasOne(DonationCertificate::class);
    }
}
