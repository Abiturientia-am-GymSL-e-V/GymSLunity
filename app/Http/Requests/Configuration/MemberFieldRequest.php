<?php

declare(strict_types=1);

namespace App\Http\Requests\Configuration;

use App\Members\MemberFields;
use App\Models\MemberFieldDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create or update a member field definition. */
class MemberFieldRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $field = $this->route('field');
        $field = $field instanceof MemberFieldDefinition ? $field : null;

        return [
            'version' => ['required', 'integer', 'min:0'], 'label' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in($field && ! $field->is_custom ? [$field->type] : ['text', 'number', 'decimal', 'date', 'boolean', 'select'])],
            'section' => ['required', Rule::in(array_keys(MemberFields::SECTIONS))],
            'is_active' => ['required', 'boolean'], 'required' => ['required', 'boolean'],
            'filterable' => ['required', 'boolean'], 'show_in_table' => ['required', 'boolean'],
            'selfservice_visible' => ['sometimes', 'boolean'],
            'selfservice_editable' => ['sometimes', 'boolean'],
            'options' => ['present', 'array', 'max:100'],
            'options.*' => ['array:value,label,active'],
            'options.*.value' => ['required', 'string', 'max:'.($field->max_length ?? 255), 'distinct:strict', Rule::notIn(['__empty__', '__all__', '__any__', '__none__'])],
            'options.*.label' => ['required', 'string', 'max:120'], 'options.*.active' => ['required', 'boolean'],
            'remove_options' => ['sometimes', 'array', 'max:100'],
            'remove_options.*' => ['required', 'string', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['required' => 'Dieses Feld ist erforderlich.', 'in' => 'Diese Auswahl ist nicht zulässig.', 'distinct' => 'Auswahlwerte müssen eindeutig sein.', 'max' => 'Der Wert ist zu lang oder die Liste zu groß.'];
    }
}
