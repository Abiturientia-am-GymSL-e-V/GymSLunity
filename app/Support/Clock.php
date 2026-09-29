<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The club's local calendar. The application stores timestamps in UTC, but
 * document dates, number years and "today" checks follow the local date, so
 * a receipt issued at 00:30 on New Year's Day is not dated in the old year.
 */
final class Clock
{
    /** Current time in the club's time zone, for printed timestamps and file names. */
    public static function localNow(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('app.display_timezone', 'Europe/Berlin'));
    }

    /**
     * Today's local date at midnight in the application time zone, the same
     * representation as the immutable_date casts, so comparisons line up.
     */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::todayString());
    }

    public static function todayString(): string
    {
        return self::localNow()->toDateString();
    }

    /**
     * Start of a local calendar day as UTC timestamp. Stored timestamps are
     * UTC, so a date filter "from 30.09." must start at 22:00 UTC the day
     * before (summer time), not at midnight UTC.
     */
    public static function dayStartUtc(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, (string) config('app.display_timezone', 'Europe/Berlin'))->startOfDay()->utc();
    }

    /** Start of the local day after $date as UTC timestamp, the exclusive end of a date filter. */
    public static function nextDayStartUtc(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, (string) config('app.display_timezone', 'Europe/Berlin'))->startOfDay()->addDay()->utc();
    }
}
