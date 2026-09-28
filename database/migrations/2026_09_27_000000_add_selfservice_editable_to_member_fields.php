<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_field_definitions', function (Blueprint $table): void {
            $table->boolean('selfservice_editable')->default(false)->after('show_in_table');
        });

        DB::table('member_field_definitions')->whereIn('key', [
            'first_name', 'middle_name', 'last_name', 'gender', 'birth_date',
            'mobile_phone', 'street', 'postal_code', 'city', 'country',
            'membership_type', 'sponsor_contribution', 'payment_method',
        ])->update(['selfservice_editable' => true]);
    }

    public function down(): void
    {
        Schema::table('member_field_definitions', function (Blueprint $table): void {
            $table->dropColumn('selfservice_editable');
        });
    }
};
