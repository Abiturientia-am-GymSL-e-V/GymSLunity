<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_invoices', function (Blueprint $table): void {
            $table->string('mandate_type', 20)->nullable()->after('mandate_sequence');
        });

        Schema::table('sepa_exports', function (Blueprint $table): void {
            $table->timestamp('reverted_at')->nullable()->after('content_hash');
            $table->foreignId('reverted_by')->nullable()->after('reverted_at')->constrained('users')->nullOnDelete();
            $table->string('reverted_by_name')->nullable()->after('reverted_by');
            $table->string('reversal_reason', 1000)->nullable()->after('reverted_by_name');
        });

        Schema::create('finance_sepa_export_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('sepa_export_uuid');
            $table->foreign('sepa_export_uuid')->references('uuid')->on('sepa_exports')->restrictOnDelete();
            $table->foreignId('finance_invoice_id')->constrained('finance_invoices')->restrictOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->string('mandate_sequence', 4);
            $table->string('status', 20)->default('exported');
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('created_at');
            $table->unique(['sepa_export_uuid', 'finance_invoice_id'], 'finance_sepa_export_invoice_unique');
            $table->index(['finance_invoice_id', 'status']);
        });

        Schema::create('finance_bank_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('original_name');
            $table->string('checksum', 64)->unique();
            $table->unsignedInteger('row_count');
            $table->unsignedInteger('imported_count');
            $table->unsignedInteger('unmatched_count');
            $table->timestamp('created_at');
        });

        Schema::create('finance_bank_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('finance_bank_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->date('booking_date')->nullable();
            $table->bigInteger('amount_cents');
            $table->string('purpose')->nullable();
            $table->string('reference')->nullable();
            $table->string('type', 30)->default('payment');
            $table->string('status', 20)->default('unmatched');
            $table->string('reason')->nullable();
            $table->foreignId('finance_invoice_id')->nullable()->constrained('finance_invoices')->nullOnDelete();
            $table->foreignId('fee_invoice_id')->nullable()->constrained('finance_invoices')->nullOnDelete();
            $table->json('raw');
            $table->timestamps();
            $table->unique(['finance_bank_import_id', 'row_number'], 'finance_bank_import_row_unique');
            $table->index(['status', 'type', 'created_at']);
        });

        DB::table('finance_invoices')
            ->where('payment_method', 'sepa_direct_debit')
            ->whereNull('mandate_type')
            ->update(['mandate_type' => 'recurring']);

        DB::table('finance_invoices')
            ->whereNotNull('sepa_export_uuid')
            ->whereNotNull('sepa_exported_at')
            ->orderBy('id')
            ->chunkById(200, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    if (! DB::table('sepa_exports')->where('uuid', $invoice->sepa_export_uuid)->exists()) {
                        continue;
                    }
                    DB::table('finance_sepa_export_items')->insertOrIgnore([
                        'sepa_export_uuid' => $invoice->sepa_export_uuid,
                        'finance_invoice_id' => $invoice->id,
                        'amount_cents' => $invoice->total_cents,
                        'mandate_sequence' => $invoice->mandate_sequence ?: 'FRST',
                        'status' => 'exported',
                        'created_at' => $invoice->sepa_exported_at,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_bank_import_rows');
        Schema::dropIfExists('finance_bank_imports');
        Schema::dropIfExists('finance_sepa_export_items');

        Schema::table('sepa_exports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reverted_by');
            $table->dropColumn(['reverted_at', 'reverted_by_name', 'reversal_reason']);
        });
        Schema::table('finance_invoices', fn (Blueprint $table) => $table->dropColumn('mandate_type'));
    }
};
