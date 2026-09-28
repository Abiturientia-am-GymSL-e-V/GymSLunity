<?php

declare(strict_types=1);

namespace App\Support;

final class Iban
{
    public static function format(?string $value): string
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', $value ?? '') ?? '');

        return trim(chunk_split($normalized, 4, ' '));
    }
}
