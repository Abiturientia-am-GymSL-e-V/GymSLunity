<?php

declare(strict_types=1);

namespace App\Http\Requests\Payments;

use App\Models\MemberFieldDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContributionsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $filterKeys = MemberFieldDefinition::query()->where('is_active', true)->where('filterable', true)->pluck('key')->all();

        return [
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'amount_mode' => ['required', Rule::in(['fixed', 'member'])],
            'amount' => ['nullable', 'required_if:amount_mode,fixed', 'decimal:0,2', 'min:0.01', 'max:9999999.99'],
            'membership_type' => ['nullable', 'string', 'max:80'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'honorary' => ['required', Rule::in(['include', 'exclude', 'only'])],
            'tax_deductible' => ['required', 'boolean'],
            'filters' => ['nullable', 'array', 'max:20'],
            'filters.*.key' => ['required', 'string', 'distinct', Rule::in($filterKeys)],
            'filters.*.value' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array{period_start: string, period_end: string, due_date: string, description: string, amount_mode: string, amount?: string|int|float|null, membership_type?: string|null, payment_method?: string|null, honorary: string, tax_deductible: bool, filters?: list<array{key: string, value: string}>}
     */
    public function contributionRun(): array
    {
        /** @var array{period_start: string, period_end: string, due_date: string, description: string, amount_mode: string, amount?: string|int|float|null, membership_type?: string|null, payment_method?: string|null, honorary: string, tax_deductible: bool, filters?: list<array{key: string, value: string}>} $data */
        $data = $this->validated();

        return $data;
    }
}
