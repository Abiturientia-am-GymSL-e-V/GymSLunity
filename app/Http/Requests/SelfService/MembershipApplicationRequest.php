<?php

declare(strict_types=1);

namespace App\Http\Requests\SelfService;

use App\Documents\SignatureImage;
use App\Rules\Iban;
use App\SelfService\PortalOptions;
use App\SelfService\PortalRules;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MembershipApplicationRequest extends SelfServiceFormRequest
{
    /** Personal data taken over from the application into the member record. */
    public const PROFILE = ['first_name', 'middle_name', 'last_name', 'gender', 'birth_date', 'mobile_phone', 'street', 'postal_code', 'city', 'country'];

    public function authorize(): bool
    {
        $this->email();

        return app(PortalRules::class)->mayJoin($this->member());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $options = app(PortalOptions::class);
        $sepa = $this->sepaSelected() ? 'required' : 'nullable';

        return [...parent::rules(),
            'first_name' => ['required', 'string', 'max:255'], 'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'], 'gender' => ['nullable', Rule::in(array_keys($options->gender()))],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'mobile_phone' => ['nullable', 'string', 'max:50'], 'street' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'], 'city' => ['required', 'string', 'max:255'], 'country' => ['required', 'string', 'max:255'],
            'membership_type' => ['required', Rule::in(array_keys($options->membership()))],
            'payment_method' => ['required', Rule::in(array_keys($options->payment()))],
            'sponsor_contribution' => [PortalOptions::isSponsorMembership((string) $this->input('membership_type')) ? 'required' : 'nullable', 'numeric', 'decimal:0,2', 'between:0.01,999.99'],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_signature' => ['nullable', 'string', 'max:'.SignatureImage::MAX_DATA_URL_LENGTH],
            'mandate_accepted' => [$this->sepaSelected() ? 'accepted' : 'nullable'],
            'mandate_signature' => [$sepa, 'string', 'max:'.SignatureImage::MAX_DATA_URL_LENGTH],
            'iban' => [$sepa, 'string', 'max:42', new Iban],
            'account_holder_first_name' => [$sepa, 'string', 'max:255'],
            'account_holder_last_name' => [$sepa, 'string', 'max:255'],
            'account_holder_street' => [$sepa, 'string', 'max:255'],
            'account_holder_postal_code' => [$sepa, 'string', 'max:20'],
            'account_holder_city' => [$sepa, 'string', 'max:255'],
            'account_holder_country' => [$sepa, 'string', 'max:255'],
        ];
    }

    public function sepaSelected(): bool
    {
        return $this->input('payment_method') === 'SEPA-Lastschrift';
    }

    public function isMinor(): bool
    {
        return CarbonImmutable::parse((string) $this->input('birth_date'))->age < 18;
    }

    protected function signatureFields(): array
    {
        return [
            'signature',
            ...($this->sepaSelected() ? ['mandate_signature'] : []),
            ...($this->isMinor() && ! empty($this->input('guardian_name')) && ! empty($this->input('guardian_signature')) ? ['guardian_signature'] : []),
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && $this->isMinor()
                && (empty($this->input('guardian_name')) || empty($this->input('guardian_signature')))) {
                $validator->errors()->add('guardian_signature', 'Für Minderjährige sind Name und Unterschrift einer sorgeberechtigten Person erforderlich.');
            }
        }];
    }
}
