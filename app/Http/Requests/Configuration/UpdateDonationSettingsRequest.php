<?php

declare(strict_types=1);

namespace App\Http\Requests\Configuration;

use App\Donations\DonationPurposes;
use App\Support\Clock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDonationSettingsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:0'],
            'donation_purpose_codes' => ['required', 'array', 'min:1'],
            'donation_purpose_codes.*' => ['required', 'string', 'distinct', Rule::in(array_keys(DonationPurposes::options()))],
            'contributions_tax_deductible' => ['required', 'boolean'],
            'tax_privilege_notice_type' => ['required', Rule::in(['exemption_notice', 'corporate_tax_attachment', 'section_60a_notice'])],
            'tax_privilege_notice_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.Clock::todayString()],
            'tax_privilege_assessment_period' => ['nullable', 'required_unless:tax_privilege_notice_type,section_60a_notice', 'string', 'max:30'],
            'certificate_machine_generated_notified' => ['required', 'boolean'],
        ];
    }
}
