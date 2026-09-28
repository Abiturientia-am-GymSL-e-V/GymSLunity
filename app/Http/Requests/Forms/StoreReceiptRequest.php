<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Documents\SignatureImage;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReceiptRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'creation_key' => ['required', 'uuid'],
            'receipt_number' => ['nullable', 'string', 'max:40', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_\/-]*\z/'],
            'receipt_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
            'amount' => ['bail', 'required', 'string', 'regex:/\A\d{1,9}(?:[.,]\d{1,2})?\z/', function (string $attribute, mixed $value, Closure $fail): void {
                if ((float) str_replace(',', '.', (string) $value) <= 0) {
                    $fail('Der Betrag muss größer als null sein.');
                }
            }],
            'currency' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/'],
            'vat_rate' => ['required', Rule::in([0, 7, 19])],
            'vat_reason' => ['nullable', 'required_unless:vat_rate,19', 'string', 'max:500'],
            'payer_source' => ['required', Rule::in(['club', 'other'])], 'payee_source' => ['required', Rule::in(['club', 'other'])],
            'payer' => ['nullable', 'required_if:payer_source,other', 'string', 'max:1000'], 'payee' => ['nullable', 'required_if:payee_source,other', 'string', 'max:1000'],
            'payer_email' => ['nullable', 'email:rfc', 'max:255'], 'payee_email' => ['nullable', 'email:rfc', 'max:255'],
            'purpose' => ['required', 'string', 'max:1000'], 'signer_name' => ['required', 'string', 'max:255'],
            'signature_method' => ['required', Rule::in(['digital', 'profile', 'drawn'])],
            'signature_data' => [Rule::requiredIf(fn (): bool => $this->input('signature_method') === 'drawn'), 'nullable', 'string', 'max:'.SignatureImage::MAX_DATA_URL_LENGTH],
            'confirmed' => ['accepted'],
        ];
    }
}
