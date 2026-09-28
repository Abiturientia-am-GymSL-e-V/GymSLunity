<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared filters for document books (search, period, choice filters).
 * The overview and its PDF report validate the same scope.
 */
abstract class DocumentFilterRequest extends FormRequest
{
    /**
     * Choice filters and their allowed values. "all" is always accepted and
     * means "no restriction".
     *
     * @return array<string, list<string>>
     */
    abstract protected function choices(): array;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
        foreach ($this->choices() as $key => $values) {
            $rules[$key] = ['nullable', Rule::in(['all', ...$values])];
        }

        return $rules;
    }

    /** @return array<string, string> validated filters with defaults */
    public function filters(): array
    {
        $data = $this->validated();
        $filters = [
            'search' => trim((string) ($data['search'] ?? '')),
            'from' => (string) ($data['from'] ?? ''),
            'to' => (string) ($data['to'] ?? ''),
        ];
        foreach (array_keys($this->choices()) as $key) {
            $filters[$key] = (string) ($data[$key] ?? 'all');
        }

        return $filters;
    }
}
