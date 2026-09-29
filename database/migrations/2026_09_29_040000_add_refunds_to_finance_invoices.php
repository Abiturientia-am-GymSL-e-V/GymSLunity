<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Refunds of paid invoices are recorded on their cancellation document. */
    public function up(): void
    {
        Schema::table('finance_invoices', function (Blueprint $table) {
            $table->date('refunded_at')->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('refunded_by_name')->nullable();
            $table->string('refund_reference')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('finance_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refunded_by');
            $table->dropColumn(['refunded_at', 'refunded_by_name', 'refund_reference']);
        });
    }
};
