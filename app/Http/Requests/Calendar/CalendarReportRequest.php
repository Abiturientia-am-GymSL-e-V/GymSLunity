<?php

declare(strict_types=1);

namespace App\Http\Requests\Calendar;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class CalendarReportRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'month' => ['nullable', 'date_format:Y-m'],
            'calendars' => ['nullable', 'array', 'max:50'],
            'calendars.*' => ['integer', 'distinct', 'exists:club_calendars,id'],
        ];
    }

    public function month(): CarbonImmutable
    {
        $month = (string) ($this->validated('month') ?: now()->format('Y-m'));

        return CarbonImmutable::createFromFormat('!Y-m', $month, config('app.timezone'));
    }

    /** @return list<int>|null */
    public function calendarIds(): ?array
    {
        $calendars = $this->validated('calendars');

        return is_array($calendars) ? array_map('intval', array_values($calendars)) : null;
    }
}
