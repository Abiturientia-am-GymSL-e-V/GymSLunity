<?php

declare(strict_types=1);

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

/** A single manual booking on a member's contribution account. */
class LedgerBookingRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'member_number' => ['required', 'integer', 'exists:members,member_number'],
            'amount' => ['required', 'decimal:0,2', 'min:0.01', 'max:9999999.99'],
            'booking_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'creation_key' => ['required', 'uuid'],
        ];
    }
}
