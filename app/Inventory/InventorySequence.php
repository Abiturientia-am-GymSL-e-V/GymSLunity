<?php

declare(strict_types=1);

namespace App\Inventory;

use Illuminate\Support\Facades\DB;

final class InventorySequence
{
    public static function next(): int
    {
        $sequence = DB::table('inventory_sequences')->where('id', 1)->lockForUpdate()->first();
        $next = (int) $sequence->next_number;

        DB::table('inventory_sequences')->where('id', 1)->update(['next_number' => $next + 1]);

        return $next;
    }
}
