<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_field_definitions', function (Blueprint $table): void {
            $table->boolean('selfservice_visible')->default(false)->after('show_in_table');
        });

        DB::table('member_field_definitions')
            ->where('selfservice_editable', true)
            ->update(['selfservice_visible' => true]);
    }

    public function down(): void
    {
        Schema::table('member_field_definitions', function (Blueprint $table): void {
            $table->dropColumn('selfservice_visible');
        });
    }
};
