<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Configuration\ClubSettings;
use App\Models\FinanceInvoice;
use App\Models\FinanceMandate;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreFinanceInvoiceRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'debtor_iban' => strtoupper(preg_replace('/\s+/', '', (string) $this->input('debtor_iban')) ?? ''),
            'recipient_country' => strtoupper((string) $this->input('recipient_country')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $sepaSelected = $this->input('payment_method') === 'sepa_direct_debit';

        return [
            'creation_key' => ['required', 'uuid'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_street' => ['required', 'string', 'max:255'],
            'recipient_postal_code' => ['required', 'string', 'max:20'],
            'recipient_city' => ['required', 'string', 'max:255'],
            'recipient_country' => ['required', 'string', 'size:2', 'regex:/\A[A-Z]{2}\z/'],
            'recipient_email' => ['required', 'email:rfc', 'max:255'],
            'buyer_reference' => ['required', 'string', 'max:100'],
            'issue_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:today'],
            'service_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'currency' => ['required', Rule::in(['EUR'])],
            'payment_method' => ['required', Rule::in(['bank_transfer', 'sepa_direct_debit', 'cash', 'card', 'other']), function (string $attribute, mixed $value, Closure $fail): void {
                if ($value === 'sepa_direct_debit' && ! app(ClubSettings::class)->sepaReady()) {
                    $fail('SEPA-Lastschrift ist erst mit Vereinsname, IBAN und Gläubiger-ID in der Vereinskonfiguration verfügbar.');
                }
            }],
            'finance_mandate_id' => [Rule::requiredIf($sepaSelected), 'nullable', 'integer', Rule::exists('finance_mandates', 'id')],
            'debtor_iban' => ['nullable', 'string', 'max:42'],
            'mandate_reference' => ['nullable', 'string', 'max:35'],
            'mandate_signed_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:issue_date'],
            'mandate_type' => ['nullable', Rule::in(['recurring', 'one_off'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'string', 'regex:/\A\d{1,6}(?:[.,]\d{1,3})?\z/', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && (float) str_replace(',', '.', $value) <= 0) {
                    $fail('Die Menge muss größer als null sein.');
                }
            }],
            'items.*.unit_code' => ['required', Rule::in(['C62', 'HUR', 'DAY'])],
            'items.*.price_mode' => ['required', Rule::in(['net', 'gross'])],
            'items.*.unit_price' => ['required', 'string', 'regex:/\A\d{1,7}(?:[.,]\d{1,2})?\z/'],
            'items.*.vat_rate' => ['required', Rule::in([0, 7, 19])],
            'items.*.tax_exemption_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $mandate = $this->mandate();
                if ($validator->errors()->isNotEmpty() || $mandate === null) {
                    return;
                }
                if ($mandate->status !== 'signed' || $mandate->signed_at === null) {
                    $validator->errors()->add('finance_mandate_id', 'Das ausgewählte Mandat ist noch nicht unterschrieben und daher nicht verwendbar.');
                } elseif ($mandate->mandate_type === 'one_off' && FinanceInvoice::query()->where('finance_mandate_id', $mandate->id)->exists()) {
                    $validator->errors()->add('finance_mandate_id', 'Dieses einmalige Mandat wurde bereits für eine Rechnung verwendet.');
                }
            },
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || app(ClubSettings::class)->enabled('small_business_regulation_enabled')) {
                    return;
                }
                foreach ((array) $this->input('items') as $index => $item) {
                    if ((int) ($item['vat_rate'] ?? 0) === 0 && trim((string) ($item['tax_exemption_reason'] ?? '')) === '') {
                        $validator->errors()->add("items.$index.tax_exemption_reason", 'Für eine steuerbefreite Position ist der Befreiungsgrund erforderlich.');

                        return;
                    }
                }
            },
        ];
    }

    /** The selected SEPA mandate, if SEPA direct debit is the payment method. */
    public function mandate(): ?FinanceMandate
    {
        if ($this->input('payment_method') !== 'sepa_direct_debit') {
            return null;
        }

        return FinanceMandate::query()->find($this->integer('finance_mandate_id'));
    }
}
