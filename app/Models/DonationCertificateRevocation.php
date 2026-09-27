<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use LogicException;

/**
 * @property int $id
 * @property int $certificate_id
 * @property int|null $revoked_by
 * @property string $revoked_by_name
 * @property string $reason
 * @property bool $originals_recovered
 * @property string $encrypted_pdf
 * @property string $pdf_sha256
 * @property CarbonImmutable $revoked_at
 * @property CarbonImmutable $created_at
 */
class DonationCertificateRevocation extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['encrypted_pdf'];

    protected function casts(): array
    {
        return [
            'originals_recovered' => 'boolean',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Widerrufe von Zuwendungsbestätigungen sind unveränderlich.'));
        static::deleting(fn () => throw new LogicException('Widerrufe von Zuwendungsbestätigungen dürfen nicht gelöscht werden.'));
    }

    /** @return BelongsTo<DonationCertificate, $this> */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(DonationCertificate::class, 'certificate_id');
    }

    public function pdf(): string
    {
        $pdf = base64_decode(Crypt::decryptString($this->encrypted_pdf), true);
        if (! is_string($pdf) || ! hash_equals($this->pdf_sha256, hash('sha256', $pdf))) {
            throw new LogicException('Die Integritätsprüfung des Widerrufsabdrucks ist fehlgeschlagen.');
        }

        return $pdf;
    }
}
