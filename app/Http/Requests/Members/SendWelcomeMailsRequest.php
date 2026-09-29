<?php

declare(strict_types=1);

namespace App\Http\Requests\Members;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

class SendWelcomeMailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateAny', Member::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'members' => ['required', 'array', 'min:1', 'max:500'],
            'members.*' => ['required', 'integer', 'min:1', 'distinct'],
            'resend' => ['required', 'boolean'],
            'request_id' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['members.max' => 'Pro Versand sind höchstens 500 Mitglieder möglich. Bitte die Auswahl verkleinern.'];
    }
}
