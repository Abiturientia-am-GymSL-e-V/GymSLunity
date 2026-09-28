<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInventoryItemRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'location' => ['required', 'string', 'max:255'],
            'responsible_person' => ['nullable', 'string', 'max:255'],
        ];
    }
}
