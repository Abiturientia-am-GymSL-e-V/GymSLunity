<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('next_number');
        });
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number', 40)->unique();
            $table->uuid('creation_key')->unique();
            $table->date('receipt_date')->index();
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('payer', 1000);
            $table->string('payee', 1000);
            $table->string('purpose', 1000);
            $table->json('snapshot');
            $table->longText('encrypted_original');
            $table->longText('encrypted_copy');
            $table->string('original_sha256', 64);
            $table->string('copy_sha256', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name');
            $table->timestamp('created_at');
        });
        Schema::create('receipt_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained()->restrictOnDelete();
            $table->string('edition', 10);
            $table->string('recipient');
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sent_by_name');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_deliveries');
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('receipt_sequences');
    }
};
