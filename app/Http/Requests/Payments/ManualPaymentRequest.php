<?php

declare(strict_types=1);

namespace App\Http\Requests\Payments;

use Illuminate\Validation\Rule;

class ManualPaymentRequest extends LedgerBookingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(), 'direction' => ['required', Rule::in(['payment', 'charge'])]];
    }
}
