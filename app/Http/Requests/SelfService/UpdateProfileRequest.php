<?php

declare(strict_types=1);

namespace App\Http\Requests\SelfService;

use App\Members\MemberValidation;
use App\Models\Member;
use App\Models\MemberFieldDefinition;
use App\SelfService\Access;
use App\SelfService\PortalOptions;
use App\SelfService\PortalView;
use App\Support\FormOfAddress;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** A member changes the fields the club released for self-service editing. */
class UpdateProfileRequest extends FormRequest
{
    /** @var Collection<int, MemberFieldDefinition>|null */
    private ?Collection $definitions = null;

    public function member(): Member
    {
        return Access::member($this);
    }

    /** @return list<string> */
    public function editableKeys(): array
    {
        return array_values(array_map(strval(...), $this->definitions()->pluck('key')->all()));
    }

    /**
     * Only released fields are considered; decimals accept a German comma.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $input = $this->only([...$this->editableKeys(), 'lock_version']);
        foreach ($this->definitions()->where('type', 'decimal') as $definition) {
            if (is_string($input[$definition->key] ?? null)) {
                $input[$definition->key] = str_replace(',', '.', $input[$definition->key]);
            }
        }

        return $input;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $member = $this->member();
        $options = app(PortalOptions::class);
        $rules = Arr::only(MemberValidation::rules($member), $this->editableKeys());
        $rules['lock_version'] = ['required', 'integer', 'min:0'];
        // The current value stays valid even if the club has since deactivated it.
        if (isset($rules['membership_type'])) {
            $rules['membership_type'] = ['bail', 'sometimes', 'required', Rule::in(array_unique([...array_keys($options->membership()), $member->membership_type]))];
        }
        if (isset($rules['payment_method'])) {
            $rules['payment_method'] = ['bail', 'sometimes', 'required', Rule::in(array_unique([...array_keys($options->payment()), $member->payment_method]))];
        }

        return $rules;
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $member = $this->member();
            $values = $validator->getData();
            if ($member->payment_method !== 'SEPA-Lastschrift' && ($values['payment_method'] ?? null) === 'SEPA-Lastschrift') {
                $validator->errors()->add('payment_method', FormOfAddress::choose('Bitte richte die SEPA-Lastschrift über ein neues, unterschriebenes Mandat ein.', 'Bitte richten Sie die SEPA-Lastschrift über ein neues, unterschriebenes Mandat ein.'));

                return;
            }
            $membershipType = (string) ($values['membership_type'] ?? $member->membership_type);
            if (PortalOptions::isSponsorMembership($membershipType)
                && (! array_key_exists('sponsor_contribution', $values) || (float) $values['sponsor_contribution'] <= 0)) {
                $validator->errors()->add('sponsor_contribution', FormOfAddress::choose('Bitte lege für die Fördermitgliedschaft einen Förderbetrag fest.', 'Bitte legen Sie für die Fördermitgliedschaft einen Förderbetrag fest.'));
            }
        }];
    }

    /**
     * Validated changes; a sponsor contribution is cleared when the member
     * leaves a sponsor membership.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $values = $this->validated();
        $membershipType = (string) ($values['membership_type'] ?? $this->member()->membership_type);
        if (! PortalOptions::isSponsorMembership($membershipType) && in_array('sponsor_contribution', $this->editableKeys(), true)) {
            $values['sponsor_contribution'] = null;
        }

        return $values;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return MemberValidation::messages();
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return MemberValidation::attributes();
    }

    /** @return Collection<int, MemberFieldDefinition> */
    private function definitions(): Collection
    {
        return $this->definitions ??= app(PortalView::class)->editableDefinitions($this->member());
    }
}
