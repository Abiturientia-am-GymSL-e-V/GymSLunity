<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donation_sequences', function (Blueprint $table) {
            $table->string('kind', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('next_number');
            $table->primary(['kind', 'year']);
        });

        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number', 40)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name');
            $table->string('donor_name');
            $table->string('donor_street');
            $table->string('donor_postal_code', 20);
            $table->string('donor_city');
            $table->string('donor_country', 2)->default('DE');
            $table->string('donor_email')->nullable();
            $table->string('donation_type', 30);
            $table->unsignedBigInteger('amount_cents');
            $table->date('donated_at');
            $table->string('purpose_code', 10);
            $table->string('purpose_label');
            $table->text('description')->nullable();
            $table->string('asset_origin', 20)->nullable();
            $table->text('valuation_document_reference')->nullable();
            $table->boolean('expense_waiver')->default(false);
            $table->timestamp('created_at');
            $table->index(['donated_at', 'id']);
            $table->index(['donation_type', 'donated_at']);
        });

        Schema::create('donation_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donation_id')->unique()->constrained('donations')->restrictOnDelete();
            $table->string('certificate_number', 40)->unique();
            $table->longText('encrypted_pdf');
            $table->string('pdf_sha256', 64);
            $table->json('snapshot');
            $table->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signed_by_name');
            $table->timestamp('signed_at');
            $table->timestamp('created_at');
        });

        Schema::create('donation_certificate_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certificate_id')->constrained('donation_certificates')->restrictOnDelete();
            $table->string('recipient');
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sent_by_name');
            $table->timestamp('created_at');
            $table->index(['certificate_id', 'created_at']);
        });

        Schema::create('donation_audit_heads', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('last_hash', 64)->nullable();
        });
        DB::table('donation_audit_heads')->insert(['id' => 1, 'last_hash' => null]);

        Schema::create('donation_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donation_id')->nullable()->constrained('donations')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('event', 40);
            $table->json('payload');
            $table->string('previous_hash', 64)->nullable();
            $table->string('event_hash', 64)->unique();
            $table->timestamp('created_at');
            $table->index(['donation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_audits');
        Schema::dropIfExists('donation_audit_heads');
        Schema::dropIfExists('donation_certificate_deliveries');
        Schema::dropIfExists('donation_certificates');
        Schema::dropIfExists('donations');
        Schema::dropIfExists('donation_sequences');
    }
};
