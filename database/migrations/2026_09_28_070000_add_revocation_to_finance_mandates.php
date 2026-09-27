<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_mandates', function (Blueprint $table) {
            $table->string('debtor_email')->nullable()->change();
            $table->timestamp('revoked_at')->nullable()->after('signed_by');
            $table->foreignId('revoked_by')->nullable()->after('revoked_at')->constrained('users')->nullOnDelete();
            $table->string('revoked_by_name')->nullable()->after('revoked_by');
            $table->string('revocation_reason', 1000)->nullable()->after('revoked_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('finance_mandates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn(['revoked_at', 'revoked_by_name', 'revocation_reason']);
            $table->string('debtor_email')->nullable(false)->change();
        });
    }
};
