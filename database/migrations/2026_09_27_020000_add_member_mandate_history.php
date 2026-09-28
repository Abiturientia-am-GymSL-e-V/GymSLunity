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
        Schema::table('member_documents', function (Blueprint $table): void {
            $table->index('member_id', 'member_documents_member_id_index');
        });
        Schema::table('member_documents', function (Blueprint $table): void {
            $table->dropUnique(['member_id', 'kind']);
        });
        Schema::table('member_documents', function (Blueprint $table): void {
            $table->unsignedInteger('sequence')->default(1)->after('kind');
            $table->string('mandate_reference')->nullable()->after('kind');
            $table->date('mandate_signed_at')->nullable()->after('mandate_reference');
            $table->timestamp('revoked_at')->nullable()->after('mandate_signed_at');
            $table->string('revocation_reason')->nullable()->after('revoked_at');
            $table->unique(['member_id', 'kind', 'sequence']);
            $table->index(['member_id', 'kind', 'revoked_at'], 'member_documents_active_mandate_index');
        });

        DB::table('member_documents')
            ->where('kind', 'sepa')
            ->orderBy('id')
            ->eachById(function (object $document): void {
                $member = DB::table('members')->where('id', $document->member_id)->first([
                    'payment_method', 'mandate_reference', 'mandate_signed_at',
                ]);
                if (! $member) {
                    return;
                }
                DB::table('member_documents')->where('id', $document->id)->update([
                    'mandate_reference' => $member->mandate_reference,
                    'mandate_signed_at' => $member->mandate_signed_at,
                    'revoked_at' => $member->payment_method === 'SEPA-Lastschrift' ? null : now(),
                    'revocation_reason' => $member->payment_method === 'SEPA-Lastschrift'
                        ? null
                        : 'Bei Einführung der Mandatshistorie nicht mehr aktiv',
                ]);
            });
    }

    public function down(): void
    {
        DB::table('member_documents')
            ->where('kind', 'sepa')
            ->select('member_id')
            ->distinct()
            ->orderBy('member_id')
            ->each(function (object $row): void {
                $latestId = DB::table('member_documents')
                    ->where('member_id', $row->member_id)
                    ->where('kind', 'sepa')
                    ->max('id');
                DB::table('member_documents')
                    ->where('member_id', $row->member_id)
                    ->where('kind', 'sepa')
                    ->where('id', '!=', $latestId)
                    ->delete();
            });

        Schema::table('member_documents', function (Blueprint $table): void {
            $table->dropIndex('member_documents_active_mandate_index');
            $table->dropUnique(['member_id', 'kind', 'sequence']);
            $table->dropColumn(['sequence', 'mandate_reference', 'mandate_signed_at', 'revoked_at', 'revocation_reason']);
            $table->unique(['member_id', 'kind']);
        });
        Schema::table('member_documents', function (Blueprint $table): void {
            $table->dropIndex('member_documents_member_id_index');
        });
    }
};
