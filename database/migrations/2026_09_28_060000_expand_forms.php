<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->timestamp('exported_at')->nullable()->after('copy_sha256');
            $table->timestamp('cancelled_at')->nullable()->after('exported_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancelled_by_name')->nullable()->after('cancelled_by');
            $table->string('cancellation_reason', 1000)->nullable()->after('cancelled_by_name');
        });

        Schema::create('finance_mandate_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('next_number');
        });

        Schema::create('finance_mandates', function (Blueprint $table) {
            $table->id();
            $table->uuid('creation_key')->unique();
            $table->string('mandate_reference', 35)->unique();
            $table->string('debtor_name');
            $table->string('debtor_street');
            $table->string('debtor_postal_code', 20);
            $table->string('debtor_city');
            $table->string('debtor_country', 2)->default('DE');
            $table->string('debtor_email');
            $table->string('iban', 42);
            $table->string('mandate_type', 20);
            $table->string('status', 20)->default('pending')->index();
            $table->text('mandate_text');
            $table->string('signing_token_hash', 64)->unique();
            $table->text('encrypted_signing_token');
            $table->timestamp('signed_at')->nullable();
            $table->string('signature_method', 20)->nullable();
            $table->string('signed_by_name')->nullable();
            $table->longText('encrypted_signature')->nullable();
            $table->longText('encrypted_pdf');
            $table->string('pdf_sha256', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name');
            $table->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('finance_invoices', function (Blueprint $table) {
            $table->foreignId('finance_mandate_id')->nullable()->after('payment_method')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('finance_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finance_mandate_id');
        });
        Schema::dropIfExists('finance_mandates');
        Schema::dropIfExists('finance_mandate_sequences');
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['exported_at', 'cancelled_at', 'cancelled_by_name', 'cancellation_reason']);
        });
    }
};
