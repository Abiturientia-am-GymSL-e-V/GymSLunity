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
        Schema::create('contribution_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained()->restrictOnDelete();
            $table->bigInteger('balance_cents')->default(0);
            $table->timestamps();
        });

        Schema::create('contribution_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date');
            $table->string('description');
            $table->json('criteria');
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->timestamp('created_at');
        });

        Schema::create('contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('contribution_accounts')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('contribution_batches')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 30)->default('contribution');
            $table->string('description');
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('paid_cents')->default(0);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date');
            $table->string('payment_method', 50)->nullable();
            $table->boolean('tax_deductible')->default(false);
            $table->string('status', 20)->default('open');
            $table->string('invoice_number', 40)->nullable()->unique();
            $table->timestamp('invoice_created_at')->nullable();
            $table->timestamp('invoice_sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'due_date']);
            $table->index(['period_start', 'period_end']);
            $table->index(['account_id', 'kind', 'period_start', 'period_end'], 'contributions_account_period_index');
        });

        Schema::create('contribution_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('contribution_accounts')->restrictOnDelete();
            $table->foreignId('contribution_id')->nullable()->constrained('contributions')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('kind', 30);
            $table->bigInteger('amount_cents');
            $table->date('booking_date');
            $table->string('reference')->nullable();
            $table->string('description');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
            $table->index(['account_id', 'booking_date']);
            $table->index(['kind', 'booking_date']);
        });

        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('next_number');
        });

        Schema::create('payment_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('original_name');
            $table->string('checksum', 64)->unique();
            $table->unsignedInteger('row_count');
            $table->unsignedInteger('imported_count');
            $table->unsignedInteger('unmatched_count');
            $table->json('result');
            $table->timestamp('created_at');
        });

        Schema::create('sepa_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('message_id')->unique();
            $table->unsignedInteger('transaction_count');
            $table->unsignedBigInteger('total_cents');
            $table->date('collection_date');
            $table->string('content_hash', 64);
            $table->timestamp('created_at');
        });

        DB::table('members')->orderBy('id')->chunkById(500, function ($members): void {
            foreach ($members as $member) {
                DB::table('contribution_accounts')->insert([
                    'member_id' => $member->id,
                    'balance_cents' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sepa_exports');
        Schema::dropIfExists('payment_imports');
        Schema::dropIfExists('invoice_sequences');
        Schema::dropIfExists('contribution_transactions');
        Schema::dropIfExists('contributions');
        Schema::dropIfExists('contribution_batches');
        Schema::dropIfExists('contribution_accounts');
    }
};
