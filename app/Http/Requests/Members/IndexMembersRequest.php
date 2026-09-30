<?php

declare(strict_types=1);

namespace App\Http\Requests\Members;

use App\Members\MemberFields;
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

    /**
     * Links saved before the office migration filter department_role and
     * club_role directly. Both are office fields now and filtered like any
     * other one; the values keep their meaning, but refer to current offices.
     * Without a filterable field of that key, the old parameter is ignored.
     */
    protected function prepareForValidation(): void
    {
        $custom = $this->input('custom');
        if ($custom !== null && ! is_array($custom)) {
            return;
        }
        $custom ??= [];
        $filterable = collect(MemberFields::temporalFields())->where('filterable', true)->pluck('key')->all();
        foreach (array_intersect(['department_role', 'club_role'], $filterable) as $key) {
            $value = $this->input($key);
            if (is_string($value) && $value !== '' && ! array_key_exists($key, $custom)) {
                $custom[$key] = $value;
            }
        }
        if ($custom !== []) {
            $this->merge(['custom' => $custom]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $custom = MemberFieldDefinition::query()->where('is_active', true)->where('is_custom', true)->whereNotIn('type', MemberFieldDefinition::TEMPORAL_TYPES)->get();
        $temporal = collect(MemberFields::temporalFields())->where('filterable', true);
        $filterKeys = [...$custom->where('filterable', true)->pluck('key')->all(), ...$temporal->pluck('key')->all()];
        $rules = [
            'q' => ['nullable', 'string', 'max:120'],
            'membership' => ['nullable', 'string', 'max:80'],
            'welcome' => ['nullable', Rule::in(['received', 'missing'])],
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
        foreach ($temporal as $field) {
            $rules['custom.'.$field['key']] = ['nullable', 'string', 'max:300'];
        }

        return $rules;
    }

    /** @return array{q: string, membership: string, welcome: string, custom: array<string, string>, sort: string, direction: 'asc'|'desc', per_page: int} */
    public function filters(): array
    {
        $data = $this->validated();

        return [
            'q' => trim($data['q'] ?? ''), 'membership' => $data['membership'] ?? '',
            'welcome' => $data['welcome'] ?? '',
            'custom' => array_map(fn ($value): string => (string) $value, array_filter($data['custom'] ?? [], fn ($value): bool => $value !== null && $value !== '')),
            'sort' => $data['sort'] ?? 'name', 'direction' => ($data['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc', 'per_page' => (int) ($data['per_page'] ?? 25),
        ];
    }
}
