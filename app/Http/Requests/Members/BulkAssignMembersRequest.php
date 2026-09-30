<?php

declare(strict_types=1);

namespace App\Http\Requests\Members;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkAssignMembersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-assignments') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $this->only(['option', 'starts_on', 'ends_on', 'note'])));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $add = $this->input('action') === 'add';

        return [
            'members' => ['required', 'array', 'min:1', 'max:250'],
            'members.*' => ['required', 'integer', 'min:1', 'distinct'],
            'configuration_version' => ['required', 'integer', 'min:0'],
            'action' => ['required', Rule::in(['add', 'end'])],
            'field' => ['required', 'string', 'max:80'],
            'option' => [$add ? 'required' : 'nullable', 'string', 'max:255'],
            'starts_on' => [$add ? 'nullable' : 'prohibited', 'date_format:Y-m-d'],
            'ends_on' => [$add ? 'nullable' : 'required', 'date_format:Y-m-d', ...($add ? ['after_or_equal:starts_on'] : [])],
            'note' => [$add ? 'nullable' : 'prohibited', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'members.required' => 'Bitte mindestens ein Mitglied auswählen.',
            'members.max' => 'Es können höchstens 250 Mitglieder gleichzeitig bearbeitet werden.',
            'option.required' => 'Bitte eine Auswahl treffen.',
            'ends_on.required' => 'Bitte ein Enddatum angeben.',
            'date_format' => 'Bitte ein gültiges Datum eingeben.',
            'after_or_equal' => 'Das Ende darf nicht vor dem Beginn liegen.',
            'max' => 'Der Text ist zu lang (maximal :max Zeichen).',
        ];
    }
}
