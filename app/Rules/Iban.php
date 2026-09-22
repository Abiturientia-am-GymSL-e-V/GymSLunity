<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class Iban implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $value)) {
            $fail('Bitte eine gültige IBAN eingeben.');

            return;
        }
        $reordered = substr($value, 4).substr($value, 0, 4);
        $digits = preg_replace_callback('/[A-Z]/', fn (array $match): string => (string) (ord($match[0]) - 55), $reordered);
        $remainder = 0;
        foreach (str_split($digits ?? '') as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }
        if ($remainder !== 1 || (str_starts_with($value, 'DE') && strlen($value) !== 22)) {
            $fail('Die IBAN-Prüfziffer oder Länge ist ungültig.');
        }
    }
}
