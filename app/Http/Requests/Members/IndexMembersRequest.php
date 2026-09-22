<?php

namespace App\Http\Requests\Members;

use App\Models\Member;
use App\Models\MemberFieldDefinition;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexMembersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Member::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $custom = MemberFieldDefinition::query()->where('is_active', true)->where('is_custom', true)->get();
        $filterKeys = $custom->where('filterable', true)->pluck('key')->all();
        $rules = [
            'q' => ['nullable', 'string', 'max:120'],
            'membership' => ['nullable', 'string', 'max:80'],
            'department_role' => ['nullable', 'string', 'max:100'],
            'club_role' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['member_number', 'name', 'city', 'birth_date', 'membership_type', 'joined_at', 'left_at', ...$custom->pluck('key')->all()])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'custom' => ['nullable', 'array', function (string $attribute, mixed $value, Closure $fail) use ($filterKeys): void {
                if (is_array($value) && array_diff(array_keys($value), $filterKeys) !== []) {
                    $fail('Ein Zusatzfeld-Filter ist nicht mehr verfügbar.');
                }
            }],
        ];
        foreach ($custom->where('filterable', true) as $field) {
            $rules['custom.'.$field->key] = ['nullable', ...match ($field->type) {
                'boolean' => [Rule::in(['0', '1'])], 'number' => ['integer', 'between:-2147483648,2147483647'],
                'decimal' => ['numeric', 'between:-99999999.99,99999999.99'], 'date' => ['date_format:Y-m-d'], default => ['string', 'max:255'],
            }];
        }

        return $rules;
    }

    /** @return array{q: string, membership: string, department_role: string, club_role: string, custom: array<string, string>, sort: string, direction: 'asc'|'desc', per_page: int} */
    public function filters(): array
    {
        $data = $this->validated();

        return [
            'q' => trim($data['q'] ?? ''), 'membership' => $data['membership'] ?? '',
            'department_role' => $data['department_role'] ?? '', 'club_role' => $data['club_role'] ?? '',
            'custom' => array_map(fn ($value): string => (string) $value, array_filter($data['custom'] ?? [], fn ($value): bool => $value !== null && $value !== '')),
            'sort' => $data['sort'] ?? 'name', 'direction' => ($data['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc', 'per_page' => (int) ($data['per_page'] ?? 25),
        ];
    }
}
