<?php

declare(strict_types=1);

namespace App\Http\Requests\Members;

use App\Members\MemberFields;
use App\Models\Member;
use Illuminate\Validation\Rule;

class ExportMembersRequest extends IndexMembersRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $fields = array_values(array_filter(MemberFields::directoryFields(), fn (array $field): bool => $field['custom'] || in_array($field['key'], Member::LIST_FIELDS, true)));

        return [...parent::rules(),
            'format' => ['required', Rule::in(['csv', 'json', 'xlsx', 'pdf', 'docx', 'print'])],
            'scope' => ['required', Rule::in(['filtered', 'selected'])],
            'selected' => ['required_if:scope,selected', 'array', 'max:10000'],
            'selected.*' => ['required', 'integer', 'min:1', 'distinct'],
            'columns' => ['required', 'array', 'min:1', 'max:250'],
            'columns.*' => ['required', 'string', 'distinct', Rule::in(['member_number', ...array_column($fields, 'key')])],
        ];
    }
}
