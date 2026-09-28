<?php

declare(strict_types=1);

namespace App\Http\Requests\Calendar;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CalendarIndexRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'month' => ['nullable', 'date_format:Y-m'],
            'view' => ['nullable', Rule::in(['month', 'list'])],
            'from' => ['nullable', 'required_with:until', 'date_format:Y-m-d'],
            'until' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->filled(['from', 'until'])) {
                    return;
                }

                $from = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('from'));
                $until = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('until'));
                if ($from && $until && $from->diffInDays($until) > 366) {
                    $validator->errors()->add('until', 'Der Zeitraum darf höchstens ein Jahr umfassen.');
                }
            },
        ];
    }

    public function month(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            '!Y-m',
            (string) ($this->validated('month') ?: now()->format('Y-m')),
            config('app.timezone'),
        );
    }

    public function viewMode(): string
    {
        return (string) ($this->validated('view') ?: 'month');
    }

    public function listFrom(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            '!Y-m-d',
            (string) ($this->validated('from') ?: $this->month()->startOfMonth()->format('Y-m-d')),
            config('app.timezone'),
        );
    }

    public function listUntil(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            '!Y-m-d',
            (string) ($this->validated('until') ?: $this->month()->endOfMonth()->format('Y-m-d')),
            config('app.timezone'),
        );
    }
}
