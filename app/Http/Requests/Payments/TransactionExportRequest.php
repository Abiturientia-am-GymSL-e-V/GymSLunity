<?php

declare(strict_types=1);

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

class TransactionExportRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', 'string', 'max:100'],
            'direction' => ['nullable', 'in:all,charge,credit'],
            'format' => ['required', 'in:csv,print'],
        ];
    }

    /** @return array{from: string, to: string, q?: string|null, kind?: string|null, direction?: string|null, format: string} */
    public function filters(): array
    {
        /** @var array{from: string, to: string, q?: string|null, kind?: string|null, direction?: string|null, format: string} $filters */
        $filters = $this->validated();

        return $filters;
    }
}
