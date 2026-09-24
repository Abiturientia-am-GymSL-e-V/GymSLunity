<?php

namespace App\Donations;

use Illuminate\Support\Facades\DB;

final class DonationSequence
{
    public static function next(string $kind, int $year): int
    {
        $sequence = DB::table('donation_sequences')
            ->where('kind', $kind)->where('year', $year)->lockForUpdate()->first();
        if (! $sequence) {
            DB::table('donation_sequences')->insert(['kind' => $kind, 'year' => $year, 'next_number' => 2]);

            return 1;
        }

        $next = (int) $sequence->next_number;
        DB::table('donation_sequences')->where('kind', $kind)->where('year', $year)->update(['next_number' => $next + 1]);

        return $next;
    }
}
