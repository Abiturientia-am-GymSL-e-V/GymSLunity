<?php

declare(strict_types=1);

namespace App\Http\Requests\Configuration;

use App\Communication\CommunicationTemplate;
use App\PublicSite\PublicPageTemplates;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class UpdatePublicPagesRequest extends FormRequest
{
    use ValidatesTemplateTexts;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer'],
            'imprint_text' => ['required', 'string', 'max:20000'],
            'privacy_text' => ['required', 'string', 'max:30000'],
            'member_access_mail_subject' => ['required', 'string', 'max:255'],
            'member_access_mail_text' => ['required', 'string', 'max:12000'],
            'join_mail_subject' => ['required', 'string', 'max:255'],
            'join_mail_text' => ['required', 'string', 'max:12000'],
            'welcome_mail_subject' => ['required', 'string', 'max:255'],
            'welcome_mail_text' => ['required', 'string', 'max:12000'],
            'member_welcome_mail_subject' => ['required', 'string', 'max:255'],
            'member_welcome_mail_text' => ['required', 'string', 'max:12000'],
            'contribution_invoice_mail_subject' => ['required', 'string', 'max:255'],
            'contribution_invoice_mail_text' => ['required', 'string', 'max:12000'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validateTemplates($validator, array_values(array_diff(array_keys(PublicPageTemplates::defaults()), PublicPageTemplates::MEMBER_TEMPLATES))),
            fn (Validator $validator) => $this->validateMemberTemplates($validator),
        ];
    }

    private function validateMemberTemplates(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }
        $templates = app(CommunicationTemplate::class);
        foreach (PublicPageTemplates::MEMBER_TEMPLATES as $key) {
            try {
                $templates->validate((string) $this->input($key), $key);
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    $validator->errors()->add($field, $messages[0]);
                }
            }
        }
    }
}
