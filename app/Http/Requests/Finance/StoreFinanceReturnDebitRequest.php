<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreFinanceReturnDebitRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['fee_amount' => str_replace(',', '.', (string) $this->input('fee_amount'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'invoice_id' => ['required', 'integer', 'exists:finance_invoices,id'],
            'fee_amount' => ['required', 'decimal:0,2', 'min:0.01', 'max:9999999.99'],
            'description' => ['required', 'string', 'max:500'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'vat_rate' => ['required', Rule::in([0, 7, 19])],
            'tax_exemption_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && (int) $this->input('vat_rate') === 0 && trim((string) $this->input('tax_exemption_reason')) === '') {
                $validator->errors()->add('tax_exemption_reason', 'Für 0 % Umsatzsteuer ist ein Befreiungs- oder Nichtsteuerbarkeitsgrund erforderlich.');
            }
        }];
    }
}
