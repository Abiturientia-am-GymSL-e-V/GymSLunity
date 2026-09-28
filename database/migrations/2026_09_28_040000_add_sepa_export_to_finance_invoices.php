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
            $table->timestamp('sepa_exported_at')->nullable()->after('cancellation_reason')->index();
            $table->uuid('sepa_export_uuid')->nullable()->after('sepa_exported_at')->index();
            $table->date('sepa_collection_date')->nullable()->after('sepa_export_uuid');
            $table->string('mandate_sequence', 4)->nullable()->after('sepa_collection_date');
        });
    }

    public function down(): void
    {
        Schema::table('finance_invoices', function (Blueprint $table): void {
            $table->dropIndex(['sepa_exported_at']);
            $table->dropIndex(['sepa_export_uuid']);
            $table->dropColumn(['sepa_exported_at', 'sepa_export_uuid', 'sepa_collection_date', 'mandate_sequence']);
        });
    }
};
