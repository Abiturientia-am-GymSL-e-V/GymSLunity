<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use LogicException;

/**
 * @property int $id
 * @property string $invoice_number
 * @property string $document_type
 * @property int|null $original_invoice_id
 * @property string $recipient_name
 * @property string $recipient_email
 * @property string $buyer_reference
 * @property CarbonImmutable $issue_date
 * @property CarbonImmutable $service_date
 * @property CarbonImmutable $due_date
 * @property string $payment_method
 * @property int|null $finance_mandate_id
 * @property string $currency
 * @property int $subtotal_cents
 * @property int $tax_cents
 * @property int $total_cents
 * @property string $status
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $sepa_exported_at
 * @property CarbonImmutable|null $sepa_collection_date
 * @property string|null $mandate_sequence
 * @property string|null $mandate_type
 * @property string|null $cancellation_reason
 * @property array<string, mixed> $snapshot
 * @property int|null $cancellations_sum_total_cents
 */
class FinanceInvoice extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['encrypted_pdf', 'encrypted_xrechnung', 'snapshot', 'creation_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'issue_date' => 'immutable_date:Y-m-d',
            'service_date' => 'immutable_date:Y-m-d',
            'due_date' => 'immutable_date:Y-m-d',
            'paid_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'sepa_exported_at' => 'immutable_datetime',
            'sepa_collection_date' => 'immutable_date:Y-m-d',
            'snapshot' => 'array',
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Ausgestellte Rechnungen dürfen nicht gelöscht werden.'));
    }

    public function pdf(): string
    {
        return $this->document('pdf');
    }

    public function xrechnung(): string
    {
        return $this->document('xrechnung');
    }

    public function filename(string $format): string
    {
        $label = $this->document_type === 'cancellation' ? 'Stornorechnung' : 'Rechnung';
        $base = $label.'_'.str_replace(['/', '\\'], '-', $this->invoice_number);

        return $base.($format === 'xrechnung' ? '_XRechnung.xml' : '.pdf');
    }

    /** @return BelongsTo<FinanceInvoice, $this> */
    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_invoice_id');
    }

    /** @return HasMany<FinanceInvoice, $this> */
    public function cancellations(): HasMany
    {
        return $this->hasMany(self::class, 'original_invoice_id');
    }

    private function document(string $format): string
    {
        if (! in_array($format, ['pdf', 'xrechnung'], true)) {
            throw new LogicException('Unbekanntes Rechnungsformat.');
        }
        $contents = base64_decode(Crypt::decryptString($this->getAttribute('encrypted_'.$format)), true);
        if (! is_string($contents) || ! hash_equals($this->getAttribute($format.'_sha256'), hash('sha256', $contents))) {
            throw new LogicException('Die Integritätsprüfung der Rechnung ist fehlgeschlagen.');
        }

        return $contents;
    }
}
