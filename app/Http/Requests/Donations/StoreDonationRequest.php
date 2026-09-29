<?php

declare(strict_types=1);

namespace App\Http\Requests\Donations;

use App\Configuration\ClubSettings;
use App\Configuration\Countries;
use App\Donations\DonationPurposes;
use App\Support\Clock;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDonationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('amount'))) {
            $this->merge(['amount' => str_replace(',', '.', trim($this->input('amount')))]);
        }
        if (is_string($this->input('donor_country'))) {
            $this->merge(['donor_country' => strtoupper(trim($this->input('donor_country')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'donor_name' => ['required', 'string', 'max:255'],
            'donor_street' => ['required', 'string', 'max:255'],
            'donor_postal_code' => ['required', 'string', 'max:20'],
            'donor_city' => ['required', 'string', 'max:255'],
            'donor_country' => ['required', 'string', Rule::in(array_keys(Countries::all()))],
            'donor_email' => ['nullable', 'email:rfc', 'max:255'],
            'donation_type' => ['required', Rule::in(['money', 'material', 'membership_fee', 'expense_waiver'])],
            'amount' => ['required', 'decimal:0,2', 'min:0.01', 'max:9999999.99'],
            'donated_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.Clock::todayString()],
            'purpose_code' => ['required', Rule::in(array_keys(DonationPurposes::options()))],
            'description' => ['nullable', 'required_if:donation_type,material', 'string', 'max:600'],
            'asset_origin' => ['nullable', 'required_if:donation_type,material', Rule::in(['business', 'private', 'unknown'])],
            'valuation_document_reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $settings = app(ClubSettings::class);
            $configuredCodes = $settings->get('donation_purpose_codes');
            if (! in_array($this->input('purpose_code'), is_array($configuredCodes) ? $configuredCodes : [], true)) {
                $validator->errors()->add('purpose_code', 'Der Zweck ist nicht in den Spenden-Stammdaten freigegeben.');
            } elseif ($this->input('donation_type') === 'membership_fee' && ! $settings->enabled('contributions_tax_deductible')) {
                $validator->errors()->add('donation_type', 'Mitgliedsbeiträge sind laut Konfiguration nicht als Zuwendung abzugsfähig.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'description.required_if' => 'Bitte die Sachzuwendung genau mit Alter, Zustand und Kaufpreis beschreiben.',
            'asset_origin.required_if' => 'Bitte die Herkunft der Sachzuwendung angeben.',
        ];
    }
}
