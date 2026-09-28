<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Inventory\InventoryOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryFilterRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'category' => [
                'nullable',
                Rule::in(array_keys(InventoryOptions::categories())),
            ],
            'status' => [
                'nullable',
                Rule::in(array_keys(InventoryOptions::statuses())),
            ],
        ];
    }
}
