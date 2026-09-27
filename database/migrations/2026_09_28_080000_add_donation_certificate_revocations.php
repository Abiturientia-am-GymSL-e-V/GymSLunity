<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_certificates', function (Blueprint $table) {
            $table->longText('encrypted_signature_image')->nullable()->after('snapshot');
        });

        Schema::create('donation_certificate_revocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certificate_id')->unique()->constrained('donation_certificates')->restrictOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoked_by_name');
            $table->text('reason');
            $table->boolean('originals_recovered');
            $table->longText('encrypted_pdf');
            $table->string('pdf_sha256', 64);
            $table->timestamp('revoked_at');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_certificate_revocations');
        Schema::table('donation_certificates', function (Blueprint $table) {
            $table->dropColumn('encrypted_signature_image');
        });
    }
};
