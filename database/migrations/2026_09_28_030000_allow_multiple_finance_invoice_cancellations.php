<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_invoices', function (Blueprint $table): void {
            $table->index('original_invoice_id');
        });
        Schema::table('finance_invoices', function (Blueprint $table): void {
            $table->dropUnique(['original_invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::table('finance_invoices', function (Blueprint $table): void {
            $table->dropIndex(['original_invoice_id']);
            $table->unique('original_invoice_id');
        });
    }
};
