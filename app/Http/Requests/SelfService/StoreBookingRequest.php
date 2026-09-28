<?php

declare(strict_types=1);

namespace App\Http\Requests\SelfService;

use App\SelfService\Access;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookingRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->input('recurrence') !== 'none' && ! $this->has('recurrence_interval')) {
            $this->merge(['recurrence_interval' => 1]);
        }
    }

    public function authorize(): bool
    {
        return Access::member($this)->isCurrentMember();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'resource_id' => ['required', 'integer', 'exists:booking_resources,id'],
            'title' => ['required', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', 'date_format:Y-m-d\\TH:i', 'after:now'], 'ends_at' => ['required', 'date_format:Y-m-d\\TH:i'],
            'recurrence' => ['required', Rule::in(['none', 'daily', 'weekly'])],
            'recurrence_interval' => ['required_if:recurrence,daily,weekly', 'integer', 'min:1', 'max:365'],
            'occurrences' => ['required_if:recurrence,daily,weekly', 'integer', 'min:1', 'max:52'],
        ];
    }
}
