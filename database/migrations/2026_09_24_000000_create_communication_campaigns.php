<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->string('format', 20)->nullable();
            $table->string('subject');
            $table->text('body');
            $table->json('filters');
            $table->unsignedInteger('recipient_count');
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name');
            $table->timestamp('created_at');
            $table->index(['kind', 'created_at']);
        });

        Schema::create('communication_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('communication_campaigns')->restrictOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->unsignedInteger('member_number');
            $table->string('recipient_name');
            $table->string('recipient_email')->nullable();
            $table->string('status', 20);
            $table->string('error', 500)->nullable();
            $table->timestamp('created_at');
            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_deliveries');
        Schema::dropIfExists('communication_campaigns');
    }
};
