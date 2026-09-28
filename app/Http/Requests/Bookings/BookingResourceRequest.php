<?php

declare(strict_types=1);

namespace App\Http\Requests\Bookings;

use App\Bookings\BookingManager;
use App\Models\BookingResource;
use App\Payments\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Create or update a bookable resource. */
class BookingResourceRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('price'))) {
            $this->merge(['price' => str_replace(',', '.', trim($this->input('price')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $membershipTypes = array_keys(app(BookingManager::class)->membershipTypes());

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:booking_resources,id'],
            'inventory_item_id' => ['nullable', 'integer', 'exists:inventory_items,id'],
            'allowed_membership_types' => ['present', 'array'],
            'allowed_membership_types.*' => ['string', Rule::in($membershipTypes)],
            'auto_approve_membership_types' => ['present', 'array'],
            'auto_approve_membership_types.*' => ['string', Rule::in($membershipTypes)],
            'price_mode' => ['required', Rule::in(['free', 'once', 'hour', 'day'])],
            'price' => ['required_unless:price_mode,free', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999.99'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $allowed = (array) $this->input('allowed_membership_types');
            if ($allowed !== [] && array_diff((array) $this->input('auto_approve_membership_types'), $allowed) !== []) {
                $validator->errors()->add('auto_approve_membership_types', 'Automatische Freigaben sind nur für zugelassene Mitgliedsarten möglich.');

                return;
            }
            $current = $this->route('resource');
            $parentId = (int) $this->input('parent_id');
            if ($current instanceof BookingResource && $parentId !== 0
                && ($parentId === $current->id || in_array($parentId, $current->descendantIds(), true))) {
                $validator->errors()->add('parent_id', 'Eine Ressource kann nicht unter sich selbst oder einer eigenen Teilressource eingeordnet werden.');
            }
        }];
    }

    /** @return array<string, mixed> model attributes */
    public function resourceAttributes(): array
    {
        $data = $this->validated();

        return [
            ...Arr::except($data, ['price']),
            'description' => ($data['description'] ?? null) ?: null,
            'location' => ($data['location'] ?? null) ?: null,
            'price_cents' => $data['price_mode'] === 'free' ? 0 : Money::cents($data['price']),
        ];
    }
}
