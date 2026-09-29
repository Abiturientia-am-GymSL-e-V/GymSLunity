<?php

declare(strict_types=1);

namespace App\Http\Requests\Calendar;

use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CalendarReportRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'layout' => ['nullable', Rule::in(['month', 'list'])],
            'month' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'required_if:layout,list', 'date_format:Y-m-d'],
            'until' => ['nullable', 'required_if:layout,list', 'date_format:Y-m-d', 'after_or_equal:from'],
            'calendars' => ['nullable', 'array', 'max:50'],
            'calendars.*' => ['integer', 'distinct', 'exists:club_calendars,id'],
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

    public function layout(): string
    {
        return (string) ($this->validated('layout') ?: 'month');
    }

    public function month(): CarbonImmutable
    {
        $month = (string) ($this->validated('month') ?: Clock::localNow()->format('Y-m'));

        return CarbonImmutable::createFromFormat('!Y-m', $month, config('app.timezone'));
    }

    /** @return list<int>|null */
    public function calendarIds(): ?array
    {
        $calendars = $this->validated('calendars');

        return is_array($calendars) ? array_map('intval', array_values($calendars)) : null;
    }

    public function from(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            '!Y-m-d',
            (string) ($this->validated('from') ?: $this->month()->startOfMonth()->format('Y-m-d')),
            config('app.timezone'),
        );
    }

    public function until(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            '!Y-m-d',
            (string) ($this->validated('until') ?: $this->month()->endOfMonth()->format('Y-m-d')),
            config('app.timezone'),
        );
    }
}
