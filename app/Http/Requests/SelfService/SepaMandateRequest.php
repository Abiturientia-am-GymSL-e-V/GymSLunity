<?php

declare(strict_types=1);

namespace App\Http\Requests\SelfService;

use App\Rules\Iban;

class SepaMandateRequest extends SelfServiceFormRequest
{
    public function authorize(): bool
    {
        $this->email();

        return $this->member()?->hasActiveOrUpcomingMembership() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(), 'iban' => ['required', 'string', 'max:42', new Iban],
            'account_holder_first_name' => ['required', 'string', 'max:255'], 'account_holder_last_name' => ['required', 'string', 'max:255'],
            'account_holder_street' => ['required', 'string', 'max:255'], 'account_holder_postal_code' => ['required', 'string', 'max:20'],
            'account_holder_city' => ['required', 'string', 'max:255'], 'account_holder_country' => ['required', 'string', 'max:255'],
        ];
    }
}
