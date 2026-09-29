<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Guards form submissions that create bookings without a document number of
 * their own: a double click or a resent request books only once. Call inside
 * the transaction of the booking, so a failed booking releases the key.
 */
final class IdempotencyKey
{
    public static function claim(string $scope, string $key): bool
    {
        return DB::table('idempotency_keys')->insertOrIgnore([
            'key' => $scope.':'.$key,
            'created_at' => now(),
        ]) === 1;
    }
}
