<?php

declare(strict_types=1);

namespace App\Configuration;

use Illuminate\Support\Facades\File;

final class Countries
{
    /** @return array<string, string> */
    public static function all(): array
    {
        return File::json(resource_path('data/countries.json'), JSON_THROW_ON_ERROR);
    }

    public static function code(?string $value): ?string
    {
        $value = trim($value ?? '');
        $countries = self::all();
        if (isset($countries[strtoupper($value)])) {
            return strtoupper($value);
        }
        foreach ($countries as $code => $name) {
            if (mb_strtolower($name) === mb_strtolower($value)) {
                return $code;
            }
        }

        return null;
    }
}
