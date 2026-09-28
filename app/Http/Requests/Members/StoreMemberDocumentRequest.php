<?php

declare(strict_types=1);

namespace App\Http\Requests\Members;

use Illuminate\Foundation\Http\FormRequest;

class StoreMemberDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('member')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['document' => ['required', 'file', 'mimes:pdf', 'max:10240']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'document.required' => 'Bitte eine PDF-Datei auswählen.',
            'document.mimes' => 'Das Dokument muss eine PDF-Datei sein.',
            'document.max' => 'Das Dokument darf höchstens 10 MB groß sein.',
        ];
    }
}
