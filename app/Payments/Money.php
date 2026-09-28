<?php

declare(strict_types=1);

namespace App\Payments;

use InvalidArgumentException;

final class Money
{
    public static function cents(string|int|float $value): int
    {
        $normalized = str_replace(',', '.', trim((string) $value));
        if (! preg_match('/^\d{1,9}(?:\.\d{1,2})?$/', $normalized)) {
            throw new InvalidArgumentException('Ungültiger Geldbetrag.');
        }

        [$euros, $cents] = array_pad(explode('.', $normalized, 2), 2, '');

        return ((int) $euros * 100) + (int) str_pad($cents, 2, '0');
    }
}
