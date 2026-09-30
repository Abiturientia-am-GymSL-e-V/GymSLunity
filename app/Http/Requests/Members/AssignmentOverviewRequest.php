<?php

declare(strict_types=1);

namespace App\Http\Requests\Members;

use App\Support\Clock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filters of the office, department and honor overviews, kept in the URL. */
class AssignmentOverviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view-assignments') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'field' => ['nullable', 'string', 'max:80'],
            'option' => ['nullable', 'string', 'max:255'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'year' => ['nullable', 'integer', 'between:1800,2200'],
            'board' => ['nullable', 'boolean'],
            'members' => ['nullable', Rule::in(['all', 'current'])],
            'format' => ['nullable', Rule::in(['csv', 'pdf'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['date_format' => 'Bitte ein gültiges Datum eingeben.', 'after_or_equal' => 'Das Ende darf nicht vor dem Beginn liegen.'];
    }

    /**
     * @return array{field: string, option: string, date: string, from: string|null, to: string|null, year: int|null, board: bool, members: string}
     */
    public function filters(): array
    {
        $data = $this->validated();

        return [
            'field' => (string) ($data['field'] ?? ''), 'option' => (string) ($data['option'] ?? ''),
            'date' => $data['date'] ?? Clock::todayString(), 'from' => $data['from'] ?? null, 'to' => $data['to'] ?? null,
            'year' => isset($data['year']) ? (int) $data['year'] : null, 'board' => (bool) ($data['board'] ?? false),
            'members' => $data['members'] ?? 'all',
        ];
    }
}
