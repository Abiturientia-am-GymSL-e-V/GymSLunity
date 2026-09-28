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
        if (! $this->has('access_rules') && $this->has('allowed_membership_types')) {
            $this->merge([
                'access_rules' => collect((array) $this->input('allowed_membership_types'))->map(
                    fn (mixed $value): array => ['field_key' => 'membership_type', 'value' => (string) $value],
                )->values()->all(),
                'auto_approve_rules' => collect((array) $this->input('auto_approve_membership_types'))->map(
                    fn (mixed $value): array => ['field_key' => 'membership_type', 'value' => (string) $value],
                )->values()->all(),
            ]);
        }
        if (is_string($this->input('price'))) {
            $this->merge(['price' => str_replace(',', '.', trim($this->input('price')))]);
        }
        if (! $this->has('pricing_rules') && in_array($this->input('price_mode'), ['hour', 'day'], true)) {
            $this->merge(['pricing_rules' => [[
                'from_value' => 0,
                'from_unit' => 'minutes',
                'unit_value' => 1,
                'unit' => $this->input('price_mode') === 'hour' ? 'hours' : 'days',
                'price' => $this->input('price'),
            ]]]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $fields = collect(app(BookingManager::class)->memberFields())->keyBy('key');

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:booking_resources,id'],
            'inventory_item_id' => ['nullable', 'integer', 'exists:inventory_items,id'],
            'access_rules' => ['present', 'array', 'max:50'],
            'access_rules.*.field_key' => ['required', 'string', Rule::in($fields->keys())],
            'access_rules.*.value' => ['required', 'string', 'max:255'],
            'auto_approve_rules' => ['present', 'array', 'max:50'],
            'auto_approve_rules.*.field_key' => ['required', 'string', Rule::in($fields->keys())],
            'auto_approve_rules.*.value' => ['required', 'string', 'max:255'],
            'price_mode' => ['required', Rule::in(['free', 'once', 'duration', 'hour', 'day'])],
            'price' => ['required_if:price_mode,once', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999.99'],
            'pricing_rules' => ['required_if:price_mode,duration,hour,day', 'array', 'max:20'],
            'pricing_rules.*.from_value' => ['required', 'integer', 'min:0', 'max:525600'],
            'pricing_rules.*.from_unit' => ['required', Rule::in(['minutes', 'hours', 'days'])],
            'pricing_rules.*.unit_value' => ['required', 'integer', 'min:1', 'max:525600'],
            'pricing_rules.*.unit' => ['required', Rule::in(['minutes', 'hours', 'days'])],
            'pricing_rules.*.price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999.99'],
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
            $fields = collect(app(BookingManager::class)->memberFields())->keyBy('key');
            foreach (['access_rules', 'auto_approve_rules'] as $group) {
                foreach ((array) $this->input($group) as $index => $rule) {
                    $allowed = collect($fields[$rule['field_key']]['options'] ?? [])->pluck('value');
                    if (! $allowed->containsStrict($rule['value'])) {
                        $validator->errors()->add("$group.$index.value", 'Dieser Feldwert ist nicht verfügbar.');
                    }
                }
            }
            $allowed = collect((array) $this->input('access_rules'))->map(fn (array $rule): string => $rule['field_key']."\0".$rule['value']);
            $automatic = collect((array) $this->input('auto_approve_rules'))->map(fn (array $rule): string => $rule['field_key']."\0".$rule['value']);
            if ($allowed->isNotEmpty() && $automatic->diff($allowed)->isNotEmpty()) {
                $key = $this->has('auto_approve_membership_types') ? 'auto_approve_membership_types' : 'auto_approve_rules';
                $validator->errors()->add($key, 'Automatische Freigaben sind nur für zugelassene Mitgliedsarten möglich.');
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
        $mode = in_array($data['price_mode'], ['hour', 'day'], true) ? 'duration' : $data['price_mode'];
        $accessRules = $this->uniqueRules($data['access_rules']);
        $autoRules = $this->uniqueRules($data['auto_approve_rules']);
        $pricingRules = [];
        foreach ((array) ($data['pricing_rules'] ?? []) as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $pricingRules[] = [
                'from_value' => (int) $rule['from_value'],
                'from_unit' => (string) $rule['from_unit'],
                'unit_value' => (int) $rule['unit_value'],
                'unit' => (string) $rule['unit'],
                'price_cents' => Money::cents($rule['price']),
            ];
        }
        usort($pricingRules, fn (array $left, array $right): int => $this->minutes($left['from_value'], $left['from_unit']) <=> $this->minutes($right['from_value'], $right['from_unit']));

        return [
            ...Arr::except($data, ['price', 'pricing_rules', 'access_rules', 'auto_approve_rules']),
            'description' => ($data['description'] ?? null) ?: null,
            'location' => ($data['location'] ?? null) ?: null,
            'price_mode' => $mode,
            'price_cents' => $mode === 'once' ? Money::cents($data['price']) : (int) ($pricingRules[0]['price_cents'] ?? 0),
            'pricing_rules' => $mode === 'duration' ? $pricingRules : [],
            'access_rules' => $accessRules,
            'auto_approve_rules' => $autoRules,
            'allowed_membership_types' => $this->legacyMembershipTypes($accessRules),
            'auto_approve_membership_types' => $this->legacyMembershipTypes($autoRules),
        ];
    }

    /** @param list<array{field_key: string, value: string}> $rules
     * @return list<array{field_key: string, value: string}>
     */
    private function uniqueRules(array $rules): array
    {
        $unique = [];
        foreach ($rules as $rule) {
            $unique[$rule['field_key']."\0".$rule['value']] = $rule;
        }

        return array_values($unique);
    }

    /** @param list<array{field_key: string, value: string}> $rules
     * @return list<string>
     */
    private function legacyMembershipTypes(array $rules): array
    {
        $types = [];
        foreach ($rules as $rule) {
            if ($rule['field_key'] === 'membership_type') {
                $types[] = $rule['value'];
            }
        }

        return $types;
    }

    private function minutes(int $value, string $unit): int
    {
        return $value * match ($unit) {
            'days' => 1440,
            'hours' => 60,
            default => 1,
        };
    }
}
