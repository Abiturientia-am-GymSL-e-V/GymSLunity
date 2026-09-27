<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use LogicException;

/**
 * @property int $id
 * @property string $mandate_reference
 * @property string $debtor_name
 * @property string|null $debtor_email
 * @property string $iban
 * @property string $mandate_type
 * @property string $status
 * @property CarbonImmutable|null $signed_at
 * @property CarbonImmutable|null $revoked_at
 */
class FinanceMandate extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['encrypted_pdf', 'encrypted_signature', 'encrypted_signing_token', 'signing_token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['signed_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function pdf(): string
    {
        $pdf = base64_decode(Crypt::decryptString($this->encrypted_pdf), true);
        if (! is_string($pdf) || ! hash_equals($this->pdf_sha256, hash('sha256', $pdf))) {
            throw new LogicException('Die Integritätsprüfung des SEPA-Mandats ist fehlgeschlagen.');
        }

        return $pdf;
    }

    public function signingToken(): string
    {
        return Crypt::decryptString($this->encrypted_signing_token);
    }

    public function filename(): string
    {
        return 'SEPA-Mandat_'.str_replace('/', '-', $this->mandate_reference).'.pdf';
    }
}
