<?php

namespace App\Http\Requests\Members;

use App\Members\MemberFields;
use App\Members\MemberValidation;
use App\Models\Member;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('member')) ?? false;
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
        /** @var Member $member */
        $member = $this->route('member');
        $rules = [
            'lock_version' => ['required', 'integer', 'min:0'],
            'close' => ['sometimes', 'boolean'],
            'return_to' => ['nullable', 'string', 'max:3000'],
            'configuration_version' => ['sometimes', 'integer', 'min:0'],
        ];
        $rules = [...$rules, ...MemberValidation::rules($member)];

        return $rules;
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $unknown = array_diff(array_keys($this->all()), [...MemberFields::writable(), 'lock_version', 'configuration_version', 'close', 'return_to', '_token', '_method']);
            if ($unknown !== []) {
                $validator->errors()->add('form', 'Die Anfrage enthält Felder, die hier nicht geändert werden dürfen.');
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return MemberValidation::attributes();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return MemberValidation::messages();
    }
}
