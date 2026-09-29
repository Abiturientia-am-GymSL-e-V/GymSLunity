<?php

declare(strict_types=1);

namespace App\Http\Requests\Configuration;

use App\Configuration\UserRoles;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Create or update an administration account. */
class UserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['email' => Str::lower(trim($this->string('email')->toString()))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->route('user');
        $user = $user instanceof User ? $user : null;

        return [
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users')->ignore($user)],
            // New accounts either get a password or an invitation mail to set one.
            'password' => [$user || $this->boolean('send_invitation') ? 'nullable' : 'required', 'string', 'max:72', 'confirmed', Password::default()],
            'send_invitation' => ['sometimes', 'boolean'],
            'roles' => ['present', 'array', 'max:7'], 'roles.*' => ['required', 'string', 'distinct', Rule::in(array_keys(UserRoles::LABELS))],
            'is_active' => ['required', 'boolean'], 'verified' => ['required', 'boolean'],
            'lock_version' => [$user ? 'required' : 'nullable', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['required' => 'Dieses Feld ist erforderlich.', 'unique' => 'Diese E-Mail-Adresse ist bereits vergeben.', 'email' => 'Bitte eine gültige E-Mail-Adresse eingeben.', 'min' => 'Das Passwort muss mindestens 12 Zeichen enthalten.', 'confirmed' => 'Die Passwörter stimmen nicht überein.', 'in' => 'Diese Rolle ist nicht zulässig.'];
    }
}
