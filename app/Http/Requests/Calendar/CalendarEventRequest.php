<?php

declare(strict_types=1);

namespace App\Http\Requests\Calendar;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CalendarEventRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'calendar_id' => ['required', 'integer', 'exists:club_calendars,id'], 'title' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'], 'ends_at' => ['required', 'date_format:Y-m-d\TH:i'], 'all_day' => ['required', 'boolean'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && $this->period()[1]->lte($this->period()[0])) {
                $validator->errors()->add('ends_at', 'Das Ende muss nach dem Beginn liegen.');
            }
        }];
    }

    /** @return array<string, mixed> event attributes without the calendar */
    public function eventAttributes(): array
    {
        $data = $this->validated();
        [$start, $end] = $this->period();

        return ['title' => $data['title'], 'location' => $data['location'] ?: null, 'description' => $data['description'] ?: null, 'starts_at' => $start, 'ends_at' => $end, 'all_day' => $this->boolean('all_day')];
    }

    /**
     * All-day events cover whole days: from the first day's start to the day after the last.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function period(): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d\TH:i', (string) $this->input('starts_at'), config('app.timezone'));
        $end = CarbonImmutable::createFromFormat('Y-m-d\TH:i', (string) $this->input('ends_at'), config('app.timezone'));
        if ($this->boolean('all_day')) {
            $start = $start->startOfDay();
            $end = $end->startOfDay()->addDay();
        }

        return [$start, $end];
    }
}
