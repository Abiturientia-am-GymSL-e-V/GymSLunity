<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_invoices', function (Blueprint $table): void {
            $table->string('document_type', 20)->default('invoice')->after('creation_key')->index();
            $table->foreignId('original_invoice_id')->nullable()->after('document_type')->unique()->constrained('finance_invoices')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('paid_by_name');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancelled_by_name')->nullable()->after('cancelled_by');
            $table->text('cancellation_reason')->nullable()->after('cancelled_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('finance_invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('original_invoice_id');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['document_type', 'cancelled_at', 'cancelled_by_name', 'cancellation_reason']);
        });
    }
};
