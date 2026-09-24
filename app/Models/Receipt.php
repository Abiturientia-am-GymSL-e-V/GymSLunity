<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use LogicException;

/**
 * @property int $id
 * @property string $receipt_number
 * @property string $creation_key
 * @property int|null $created_by
 * @property array<string, mixed> $snapshot
 * @property CarbonImmutable $receipt_date
 * @property int $amount_cents
 * @property string $currency
 * @property string $payer
 * @property string $payee
 * @property string $purpose
 */
class Receipt extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['encrypted_original', 'encrypted_copy', 'snapshot', 'creation_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'receipt_date' => 'immutable_date:Y-m-d', 'amount_cents' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ausgestellte Quittungen sind unveränderlich.'));
        static::deleting(fn () => throw new LogicException('Ausgestellte Quittungen dürfen nicht gelöscht werden.'));
    }

    public function pdf(string $edition): string
    {
        if (! in_array($edition, ['original', 'copy'], true)) {
            throw new LogicException('Unbekanntes Exemplar.');
        }
        $pdf = base64_decode(Crypt::decryptString($this->getAttribute('encrypted_'.$edition)), true);
        if (! is_string($pdf) || ! hash_equals($this->getAttribute($edition.'_sha256'), hash('sha256', $pdf))) {
            throw new LogicException('Die Integritätsprüfung der Quittung ist fehlgeschlagen.');
        }

        return $pdf;
    }

    public function filename(string $edition): string
    {
        return 'Quittung_'.str_replace('/', '-', $this->receipt_number).'_'.($edition === 'original' ? 'Original' : 'Kopie').'.pdf';
    }
}
