<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Rules\Iban;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinanceMandateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'iban' => strtoupper(preg_replace('/\s+/', '', (string) $this->input('iban')) ?? ''),
            'debtor_country' => strtoupper((string) $this->input('debtor_country')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'creation_key' => ['required', 'uuid'], 'debtor_name' => ['required', 'string', 'max:255'],
            'debtor_street' => ['required', 'string', 'max:255'], 'debtor_postal_code' => ['required', 'string', 'max:20'],
            'debtor_city' => ['required', 'string', 'max:255'], 'debtor_country' => ['required', 'string', 'size:2'],
            'debtor_email' => ['nullable', 'email:rfc', 'max:255'], 'iban' => ['required', 'string', 'max:42', new Iban],
            'mandate_type' => ['required', Rule::in(['recurring', 'one_off'])],
        ];
    }
}
