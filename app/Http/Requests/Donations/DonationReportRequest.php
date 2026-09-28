<?php

declare(strict_types=1);

namespace App\Http\Requests\Donations;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DonationReportRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
            'donation_type' => ['nullable', Rule::in(['money', 'material', 'membership_fee', 'expense_waiver'])],
            'certificate_status' => ['nullable', Rule::in(['open', 'issued', 'revoked'])],
        ];
    }
}
