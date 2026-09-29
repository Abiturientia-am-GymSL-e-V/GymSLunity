<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Allocates consecutive document numbers. The counter row is created if
 * missing and then locked, so parallel requests never receive the same
 * number and the first document of a year cannot collide on insert.
 * Must run inside the transaction that stores the document.
 */
final class DocumentSequence
{
    /** @param array<string, int|string> $key e.g. ['year' => 2026] or ['kind' => 'money', 'year' => 2026] */
    public static function next(string $table, array $key): int
    {
        DB::table($table)->insertOrIgnore([...$key, 'next_number' => 1]);
        $current = (int) DB::table($table)->where($key)->lockForUpdate()->value('next_number');
        DB::table($table)->where($key)->update(['next_number' => $current + 1]);

        return $current;
    }
}
