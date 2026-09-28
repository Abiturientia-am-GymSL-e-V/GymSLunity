<?php

declare(strict_types=1);

namespace App\Http\Requests\Configuration;

use App\SelfService\FormTemplates;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSelfServiceSettingsRequest extends FormRequest
{
    use ValidatesTemplateTexts;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer'], 'selfservice_enabled' => ['required', 'boolean'], 'public_join_enabled' => ['required', 'boolean'],
            'membership_activation' => ['required', Rule::in(['immediate', 'approval'])],
            'application_text' => ['required', 'string', 'max:12000'], 'sepa_text' => ['required', 'string', 'max:12000'], 'guardian_text' => ['required', 'string', 'max:6000'],
            'receipt_notes' => ['required', 'string', 'max:12000'],
            'receipt_donation_notes' => ['required', 'string', 'max:12000'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateTemplates($validator, array_keys(FormTemplates::defaults()))];
    }
}
