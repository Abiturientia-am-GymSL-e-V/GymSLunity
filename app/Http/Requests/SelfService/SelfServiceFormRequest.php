<?php

declare(strict_types=1);

namespace App\Http\Requests\SelfService;

use App\Documents\SignatureImage;
use App\Models\Member;
use App\SelfService\Access;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * Signed self-service forms (membership application, SEPA mandate).
 * Requires a verified e-mail address in the session; a signed-in member
 * is optional for applications.
 */
abstract class SelfServiceFormRequest extends FormRequest
{
    /** @var array<string, string> signature field => normalized PNG data URL */
    private array $signatures = [];

    public function email(): string
    {
        return Access::email($this);
    }

    public function member(): ?Member
    {
        return $this->session()->get('selfservice.member_id') ? Access::member($this) : null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('iban'))) {
            $this->merge(['iban' => strtoupper(preg_replace('/\s+/', '', $this->input('iban')) ?? '')]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer'], 'lock_version' => ['nullable', 'integer'],
            'accepted' => ['accepted'], 'signature' => ['required', 'string', 'max:'.SignatureImage::MAX_DATA_URL_LENGTH],
        ];
    }

    /** @return list<string> signature fields that must be decoded, in reporting order */
    protected function signatureFields(): array
    {
        return ['signature'];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach ($this->signatureFields() as $field) {
                try {
                    $this->signatures[$field] = 'data:image/png;base64,'.base64_encode(SignatureImage::fromDataUrl((string) $this->input($field)));
                } catch (InvalidArgumentException $exception) {
                    $validator->errors()->add($field, $exception->getMessage());

                    return;
                }
            }
        }];
    }

    public function signature(string $field = 'signature'): ?string
    {
        return $this->signatures[$field] ?? null;
    }
}
