<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

trait NormalizesMoneyInput
{
    /** Accept a German decimal comma in amount fields. */
    private function normalizeMoney(string $field): void
    {
        if (is_string($this->input($field))) {
            $this->merge([$field => str_replace(',', '.', trim($this->input($field)))]);
        }
    }
}
