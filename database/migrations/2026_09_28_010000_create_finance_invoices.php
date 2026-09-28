<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_invoice_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('next_number');
        });

        Schema::create('finance_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 40)->unique();
            $table->uuid('creation_key')->unique();
            $table->string('recipient_name');
            $table->string('recipient_street');
            $table->string('recipient_postal_code', 20);
            $table->string('recipient_city');
            $table->string('recipient_country', 2);
            $table->string('recipient_email');
            $table->string('buyer_reference', 100);
            $table->date('issue_date')->index();
            $table->date('service_date');
            $table->date('due_date')->index();
            $table->string('payment_method', 30);
            $table->string('currency', 3)->default('EUR');
            $table->unsignedBigInteger('subtotal_cents');
            $table->unsignedBigInteger('tax_cents');
            $table->unsignedBigInteger('total_cents');
            $table->string('status', 20)->default('open')->index();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('paid_by_name')->nullable();
            $table->json('snapshot');
            $table->longText('encrypted_pdf');
            $table->string('pdf_sha256', 64);
            $table->longText('encrypted_xrechnung');
            $table->string('xrechnung_sha256', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name');
            $table->timestamps();
        });

        Schema::create('finance_invoice_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finance_invoice_id')->constrained()->restrictOnDelete();
            $table->string('recipient');
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sent_by_name');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_invoice_deliveries');
        Schema::dropIfExists('finance_invoices');
        Schema::dropIfExists('finance_invoice_sequences');
    }
};
