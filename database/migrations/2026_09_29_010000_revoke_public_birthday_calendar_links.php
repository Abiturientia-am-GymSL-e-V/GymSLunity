<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Birthdays are personal data and must never be subscribable publicly.
        DB::table('club_calendars')->where('type', 'birthdays')->update(['public_token' => null]);
    }

    public function down(): void {}
};
