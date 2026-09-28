<?php

declare(strict_types=1);

namespace App\Http\Requests\Members;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

class PreviewMemberImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Member::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['csv' => ['required', 'file', 'mimes:csv,txt', 'max:2048']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'csv.required' => 'Bitte eine CSV-Datei auswählen.',
            'csv.mimes' => 'Bitte eine CSV-Datei hochladen.',
            'csv.max' => 'Die CSV-Datei darf höchstens 2 MB groß sein.',
        ];
    }
}
