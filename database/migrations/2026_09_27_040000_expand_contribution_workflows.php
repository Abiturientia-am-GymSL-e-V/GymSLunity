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
        Schema::table('members', function (Blueprint $table): void {
            $table->string('mandate_type', 20)->default('recurring')->after('mandate_signed_at');
        });

        if (! DB::table('member_field_definitions')->where('key', 'mandate_type')->exists()) {
            DB::table('member_field_definitions')->insert([
                'key' => 'mandate_type',
                'label' => 'Mandatsart',
                'type' => 'select',
                'section' => 'bank',
                'position' => 35,
                'is_active' => true,
                'is_custom' => false,
                'required' => true,
                'filterable' => false,
                'show_in_table' => false,
                'selfservice_editable' => false,
                'options' => json_encode([
                    ['value' => 'recurring', 'label' => 'Wiederkehrend', 'active' => true],
                    ['value' => 'one_off', 'label' => 'Einmalig', 'active' => true],
                ], JSON_THROW_ON_ERROR),
                'max_length' => 20,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('contributions', function (Blueprint $table): void {
            $table->string('payment_reference', 35)->nullable()->unique()->after('description');
            $table->string('mandate_reference')->nullable()->after('payment_method');
            $table->string('mandate_sequence', 4)->nullable()->after('mandate_reference');
            $table->timestamp('sepa_exported_at')->nullable()->after('mandate_sequence');
        });

        DB::table('contributions')->orderBy('id')->chunkById(500, function ($contributions): void {
            foreach ($contributions as $contribution) {
                $memberNumber = DB::table('contribution_accounts')
                    ->join('members', 'members.id', '=', 'contribution_accounts.member_id')
                    ->where('contribution_accounts.id', $contribution->account_id)
                    ->value('members.member_number');
                DB::table('contributions')->where('id', $contribution->id)->update([
                    'payment_reference' => 'GYMSL-'.(int) $memberNumber.'-'.$contribution->id,
                ]);
            }
        });

        Schema::create('payment_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_import_id')->constrained('payment_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->date('booking_date')->nullable();
            $table->bigInteger('amount_cents');
            $table->string('purpose')->nullable();
            $table->string('reference')->nullable();
            $table->string('type', 30)->default('payment');
            $table->string('status', 20)->default('unmatched');
            $table->string('reason')->nullable();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('contribution_transactions')->nullOnDelete();
            $table->json('raw');
            $table->timestamps();
            $table->unique(['payment_import_id', 'row_number']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_import_rows');
        Schema::table('contributions', function (Blueprint $table): void {
            $table->dropUnique('contributions_payment_reference_unique');
            $table->dropColumn(['payment_reference', 'mandate_reference', 'mandate_sequence', 'sepa_exported_at']);
        });
        DB::table('member_field_definitions')->where('key', 'mandate_type')->delete();
        Schema::table('members', fn (Blueprint $table) => $table->dropColumn('mandate_type'));
    }
};
