<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_resources', function (Blueprint $table): void {
            $table->json('access_rules')->nullable()->after('auto_approve_membership_types');
            $table->json('auto_approve_rules')->nullable()->after('access_rules');
            $table->json('pricing_rules')->nullable()->after('price_cents');
        });

        Schema::table('resource_bookings', function (Blueprint $table): void {
            $table->foreignId('finance_invoice_id')->nullable()->after('refund_transaction_id')
                ->constrained('finance_invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('resource_bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('finance_invoice_id');
        });
        Schema::table('booking_resources', function (Blueprint $table): void {
            $table->dropColumn(['access_rules', 'auto_approve_rules', 'pricing_rules']);
        });
    }
};
