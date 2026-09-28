<?php

declare(strict_types=1);

namespace App\Support;

use App\Configuration\ClubSettings;

final class FormOfAddress
{
    public const INFORMAL = 'du';

    public const FORMAL = 'sie';

    /** @param array<string, mixed>|null $club */
    public static function value(?array $club = null): string
    {
        if ($club === null) {
            try {
                $club = app(ClubSettings::class)->data();
            } catch (\Throwable) {
                return self::INFORMAL;
            }
        }

        return ($club['form_of_address'] ?? self::INFORMAL) === self::FORMAL
            ? self::FORMAL
            : self::INFORMAL;
    }

    /** @param array<string, mixed>|null $club */
    public static function isFormal(?array $club = null): bool
    {
        return self::value($club) === self::FORMAL;
    }

    /** @param array<string, mixed>|null $club */
    public static function choose(string $informal, string $formal, ?array $club = null): string
    {
        return self::isFormal($club) ? $formal : $informal;
    }
}
