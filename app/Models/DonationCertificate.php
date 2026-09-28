<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Crypt;
use LogicException;

/**
 * @property int $id
 * @property int $donation_id
 * @property string $certificate_number
 * @property string $encrypted_pdf
 * @property string $pdf_sha256
 * @property array<string, mixed> $snapshot
 * @property string|null $encrypted_signature_image
 * @property int|null $signed_by
 * @property string $signed_by_name
 * @property CarbonImmutable $signed_at
 * @property CarbonImmutable $created_at
 * @property Donation $donation
 * @property DonationCertificateRevocation|null $revocation
 */
class DonationCertificate extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['encrypted_pdf', 'encrypted_signature_image'];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'signed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ausgestellte Zuwendungsbestätigungen sind unveränderlich.'));
        static::deleting(fn () => throw new LogicException('Ausgestellte Zuwendungsbestätigungen dürfen nicht gelöscht werden.'));
    }

    /** @return BelongsTo<Donation, $this> */
    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    /** @return HasMany<DonationCertificateDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(DonationCertificateDelivery::class, 'certificate_id');
    }

    /** @return HasOne<DonationCertificateRevocation, $this> */
    public function revocation(): HasOne
    {
        return $this->hasOne(DonationCertificateRevocation::class, 'certificate_id');
    }

    public function signatureImage(): ?string
    {
        return $this->encrypted_signature_image === null
            ? null
            : Crypt::decryptString($this->encrypted_signature_image);
    }

    public function pdf(): string
    {
        $pdf = base64_decode(Crypt::decryptString($this->encrypted_pdf), true);
        if (! is_string($pdf) || ! hash_equals($this->pdf_sha256, hash('sha256', $pdf))) {
            throw new LogicException('Die Integritätsprüfung der Zuwendungsbestätigung ist fehlgeschlagen.');
        }

        return $pdf;
    }
}
