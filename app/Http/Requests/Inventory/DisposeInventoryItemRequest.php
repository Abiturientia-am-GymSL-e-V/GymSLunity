<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Models\InventoryItem;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class DisposeInventoryItemRequest extends FormRequest
{
    use NormalizesMoneyInput;

    protected function prepareForValidation(): void
    {
        // Report an existing disposal before any field errors, as before.
        if ($this->item()->status !== 'active') {
            throw ValidationException::withMessages(['status' => 'Für diesen Gegenstand wurde bereits ein Abgang erfasst.']);
        }
        $this->normalizeMoney('disposal_proceeds');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['sold', 'lost', 'disposed'])],
            'disposed_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'disposal_proceeds' => ['nullable', 'required_if:status,sold', 'decimal:0,2', 'min:0', 'max:9999999.99'],
            'disposal_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && CarbonImmutable::parse((string) $this->input('disposed_at'))->lessThan($this->item()->acquisition_date)) {
                $validator->errors()->add('disposed_at', 'Das Abgangsdatum darf nicht vor dem Anschaffungsdatum liegen.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['disposal_proceeds.required_if' => 'Bitte den Verkaufserlös angeben.'];
    }

    private function item(): InventoryItem
    {
        $item = $this->route('inventoryItem');
        abort_unless($item instanceof InventoryItem, 404);

        return $item;
    }
}
