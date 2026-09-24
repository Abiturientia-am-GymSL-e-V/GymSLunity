<?php

namespace App\Http\Requests\Members;

use App\Members\MemberFields;
use App\Members\MemberValidation;
use App\Models\Member;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BulkUpdateMembersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateAny', Member::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $values = $this->input('values');
        if (! is_array($values)) {
            return;
        }
        if (isset($values['iban']) && is_string($values['iban'])) {
            $values['iban'] = strtoupper(preg_replace('/\s+/', '', $values['iban']) ?? '') ?: null;
        }
        foreach (MemberFields::sections() as $section) {
            foreach ($section['fields'] as $field) {
                if ($field['type'] === 'decimal' && isset($values[$field['key']]) && is_string($values[$field['key']])) {
                    $values[$field['key']] = str_replace(',', '.', $values[$field['key']]);
                }
            }
        }
        $this->merge(['values' => $values]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'members' => ['required', 'array', 'min:1', 'max:250'],
            'members.*' => ['required', 'integer', 'min:1', 'distinct'],
            'configuration_version' => ['required', 'integer', 'min:0'],
            'values' => ['required', 'array', 'min:1'],
        ];
        foreach (MemberValidation::rules(new Member) as $key => $fieldRules) {
            $rules['values.'.$key] = $fieldRules;
        }

        return $rules;
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $values = $this->input('values');
            if (! is_array($values)) {
                return;
            }
            $unknown = array_diff(array_keys($values), MemberFields::writable());
            if ($unknown !== []) {
                $validator->errors()->add('form', 'Die Anfrage enthält Felder, die nicht gemeinsam geändert werden dürfen.');
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return collect(MemberValidation::attributes())->mapWithKeys(fn (string $label, string $key): array => ['values.'.$key => $label])->all();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [...MemberValidation::messages(), 'members.max' => 'Es können höchstens 250 Mitglieder gleichzeitig bearbeitet werden.'];
    }
}
