<?php

declare(strict_types=1);

namespace App\Http\Requests\Members;

use App\Members\MemberFields;
use App\Members\MemberValidation;
use App\Models\Member;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Member::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('iban'))) {
            $this->merge(['iban' => strtoupper(preg_replace('/\s+/', '', $this->input('iban')) ?? '') ?: null]);
        }
        foreach (MemberFields::sections() as $section) {
            foreach ($section['fields'] as $field) {
                if ($field['type'] === 'decimal' && is_string($this->input($field['key']))) {
                    $this->merge([$field['key'] => str_replace(',', '.', $this->input($field['key']))]);
                }
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'member_number' => ['bail', 'required', 'integer', 'min:1', 'max:4294967295', Rule::unique('members', 'member_number')],
            'configuration_version' => ['required', 'integer', 'min:0'],
            'application_file' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'sepa_file' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            ...MemberValidation::rules(new Member),
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $unknown = array_diff(array_keys($this->all()), [...MemberFields::writable(), 'member_number', 'configuration_version', 'application_file', 'sepa_file', '_token']);
            if ($unknown !== []) {
                $validator->errors()->add('form', 'Die Anfrage enthält Felder, die hier nicht angelegt werden dürfen.');
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['member_number' => 'Mitgliedsnummer', 'application_file' => 'Schriftlicher Antrag', 'sepa_file' => 'SEPA-Mandat', ...MemberValidation::attributes()];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'member_number.unique' => 'Diese Mitgliedsnummer ist bereits vergeben.',
            '*.mimes' => ':attribute muss eine PDF-Datei sein.',
            '*.max' => ':attribute darf höchstens 10 MB groß sein.',
            ...MemberValidation::messages(),
        ];
    }
}
