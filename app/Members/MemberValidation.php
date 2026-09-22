<?php

namespace App\Members;

use App\Models\Member;
use App\Rules\Iban;
use Illuminate\Validation\Rule;

final class MemberValidation
{
    /** @return array<string, mixed> */
    public static function rules(Member $member): array
    {
        $rules = [];
        foreach (MemberFields::sections() as $section) {
            foreach ($section['fields'] as $field) {
                $checks = match ($field['type']) {
                    'boolean' => ['boolean'],
                    'date' => ['date_format:Y-m-d'],
                    'number' => ['integer', 'between:-2147483648,2147483647'],
                    'decimal' => ['numeric', 'decimal:0,2', $field['key'] === 'sponsor_contribution' ? 'between:0,999.99' : 'between:-99999999.99,99999999.99'],
                    'email' => ['email:rfc', 'max:255'],
                    'select' => ['string', 'max:'.$field['max'], Rule::in(array_unique([...array_keys($field['activeOptions']), MemberFields::snapshot($member)[$field['key']] ?? null]))],
                    default => ['string', 'max:'.$field['max']],
                };
                $rules[$field['key']] = ['bail', 'sometimes', $field['required'] || ($field['type'] === 'boolean' && ! $field['custom']) ? 'required' : 'nullable', ...$checks];
            }
        }
        if (isset($rules['iban'])) {
            $rules['iban'][] = new Iban;
        }

        return $rules;
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        $labels = [];
        foreach (MemberFields::sections() as $section) {
            foreach ($section['fields'] as $field) {
                $labels[$field['key']] = $field['label'];
            }
        }

        return $labels;
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'required' => ':attribute darf nicht leer sein.',
            'string' => ':attribute muss Text enthalten.',
            'max' => ':attribute ist zu lang (maximal :max Zeichen).',
            'date_format' => ':attribute muss ein gültiges Datum sein.',
            'integer' => ':attribute muss eine ganze Zahl sein.',
            'between' => ':attribute muss zwischen :min und :max liegen.',
            'in' => 'Bitte eine gültige Auswahl für :attribute treffen.',
            'email' => 'Bitte eine gültige E-Mail-Adresse eingeben.',
            'boolean' => ':attribute muss Ja oder Nein sein.',
            'decimal' => ':attribute darf höchstens zwei Nachkommastellen enthalten.',
            'numeric' => ':attribute muss eine Zahl sein.',
        ];
    }
}
