<?php

declare(strict_types=1);

namespace App\Http\Requests\Configuration;

use App\Configuration\ClubData;
use App\Rules\Iban;
use App\Support\FormOfAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClubRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('form_of_address')) {
            $this->merge(['form_of_address' => FormOfAddress::value()]);
        }
        if (is_string($this->input('iban'))) {
            $this->merge(['iban' => strtoupper(preg_replace('/\s+/', '', $this->input('iban')) ?? '') ?: null]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ['version' => ['required', 'integer', 'min:0']];
        foreach (ClubData::fields() as $field) {
            $rules[$field['key']] = [$field['required'] ? 'required' : 'nullable', ...match ($field['type']) {
                'boolean' => ['boolean'], 'date' => ['date_format:Y-m-d'], 'email' => ['email:rfc', 'max:255'], 'url' => ['url:http,https', 'max:255'], 'select' => [Rule::in(array_keys($field['options']))], default => ['string', 'max:255'],
            }];
        }
        $rules['iban'][] = new Iban;

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['required' => ':attribute darf nicht leer sein.', 'email' => 'Bitte eine gültige E-Mail-Adresse eingeben.', 'url' => 'Bitte eine vollständige URL mit https:// oder http:// eingeben.', 'max' => ':attribute ist zu lang.'];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return array_column(ClubData::fields(), 'label', 'key');
    }
}
