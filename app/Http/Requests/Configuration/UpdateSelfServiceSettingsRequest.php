<?php

declare(strict_types=1);

namespace App\Http\Requests\Configuration;

use App\SelfService\EmailAddressFilter;
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
            'email_filter_mode' => ['required', Rule::in(EmailAddressFilter::MODES)],
            'email_filter_patterns' => ['present', 'nullable', 'string', 'max:60000'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validateTemplates($validator, array_keys(FormTemplates::defaults())),
            fn (Validator $validator) => $this->validateEmailFilter($validator),
        ];
    }

    private function validateEmailFilter(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['email_filter_mode', 'email_filter_patterns'])) {
            return;
        }
        $lines = (string) $this->input('email_filter_patterns');
        $errors = EmailAddressFilter::errors($lines);
        // One key per line, because Inertia only passes the first message of a key.
        foreach (array_slice($errors, 0, 5, true) as $line => $message) {
            $validator->errors()->add('email_filter_patterns.'.$line, $message);
        }
        if (count($errors) > 5) {
            $validator->errors()->add('email_filter_patterns', 'Weitere '.(count($errors) - 5).' Zeilen sind ungültig.');
        }
        $patterns = EmailAddressFilter::parse($lines);
        if (count($patterns) > EmailAddressFilter::MAX_PATTERNS) {
            $validator->errors()->add('email_filter_patterns', 'Es sind höchstens '.EmailAddressFilter::MAX_PATTERNS.' Einträge möglich.');
        }
        if ($this->input('email_filter_mode') === 'allow' && $patterns === []) {
            $validator->errors()->add('email_filter_patterns', 'Für eine Allowlist ist mindestens ein Eintrag nötig, sonst wäre keine Adresse mehr zugelassen.');
        }
    }
}
