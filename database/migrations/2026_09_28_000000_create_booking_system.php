<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_resources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('booking_resources')->restrictOnDelete();
            $table->foreignId('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->json('allowed_membership_types')->nullable();
            $table->json('auto_approve_membership_types')->nullable();
            $table->string('price_mode', 20)->default('free');
            $table->unsignedBigInteger('price_cents')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['parent_id', 'is_active']);
        });

        Schema::create('resource_bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained('booking_resources')->restrictOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('requester_name')->nullable();
            $table->string('title');
            $table->text('notes')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->uuid('series_id')->nullable();
            $table->unsignedSmallInteger('occurrence')->default(1);
            $table->string('status', 20)->default('requested');
            $table->unsignedBigInteger('price_cents')->default(0);
            $table->foreignId('charge_transaction_id')->nullable()->constrained('contribution_transactions')->nullOnDelete();
            $table->foreignId('refund_transaction_id')->nullable()->constrained('contribution_transactions')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_by_name')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['resource_id', 'starts_at', 'ends_at']);
            $table->index(['status', 'starts_at']);
            $table->index(['member_id', 'starts_at']);
            $table->index(['series_id', 'occurrence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_bookings');
        Schema::dropIfExists('booking_resources');
    }
};
