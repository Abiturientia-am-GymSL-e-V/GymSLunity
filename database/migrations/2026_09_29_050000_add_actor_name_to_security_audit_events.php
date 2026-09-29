<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Keeps the name readable in the audit log after the account is deleted. */
    public function up(): void
    {
        Schema::table('security_audit_events', function (Blueprint $table) {
            $table->string('actor_name')->nullable()->after('user_id');
        });
        DB::table('users')->select(['id', 'name'])->orderBy('id')->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                DB::table('security_audit_events')->where('user_id', $user->id)->update(['actor_name' => $user->name]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('security_audit_events', function (Blueprint $table) {
            $table->dropColumn('actor_name');
        });
    }
};
