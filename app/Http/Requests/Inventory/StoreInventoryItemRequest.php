<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Inventory\InventoryOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryItemRequest extends FormRequest
{
    use NormalizesMoneyInput;

    protected function prepareForValidation(): void
    {
        $this->normalizeMoney('acquisition_cost');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(array_keys(InventoryOptions::categories()))],
            'description' => ['nullable', 'string', 'max:2000'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'location' => ['required', 'string', 'max:255'],
            'responsible_person' => ['nullable', 'string', 'max:255'],
            'acquisition_type' => ['required', Rule::in(array_keys(InventoryOptions::acquisitionTypes()))],
            'acquisition_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'acquisition_cost' => ['required', 'decimal:0,2', 'min:0', 'max:9999999.99'],
            'document_reference' => ['nullable', 'string', 'max:255'],
            'depreciation_method' => ['required', Rule::in(array_keys(InventoryOptions::depreciationMethods()))],
            'useful_life_years' => ['nullable', 'required_if:depreciation_method,linear', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['useful_life_years.required_if' => 'Bitte die betriebsgewöhnliche Nutzungsdauer angeben.'];
    }
}
