<?php

declare(strict_types=1);

namespace App\Http\Requests\Bookings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A booking entered by the administration, optionally without a member. */
class StoreManualBookingRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'resource_id' => ['required', 'integer', 'exists:booking_resources,id'],
            'member_id' => ['nullable', 'integer', 'exists:members,id'],
            'requester_name' => ['required_without:member_id', 'nullable', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'recurrence' => ['required', Rule::in(['none', 'weekly', 'monthly'])],
            'occurrences' => ['required_if:recurrence,weekly,monthly', 'integer', 'min:1', 'max:52'],
        ];
    }
}
