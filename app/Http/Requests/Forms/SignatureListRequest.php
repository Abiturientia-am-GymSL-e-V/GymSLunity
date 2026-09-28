<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Forms\SignatureListColumns;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SignatureListRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'event_date' => ['nullable', 'date_format:Y-m-d'],
            'member_numbers' => ['required', 'array', 'min:1', 'max:1000'],
            'member_numbers.*' => ['required', 'integer', 'distinct', Rule::exists('members', 'member_number')],
            'columns' => ['required', 'array', 'min:1', 'max:12'],
            'columns.*' => ['required', 'string', 'distinct', Rule::in(SignatureListColumns::keys())],
        ];
    }
}
