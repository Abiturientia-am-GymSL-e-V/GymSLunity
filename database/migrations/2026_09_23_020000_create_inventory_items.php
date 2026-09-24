<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_sequences', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('next_number');
        });
        DB::table('inventory_sequences')->insert(['id' => 1, 'next_number' => 1]);

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('inventory_number', 30)->unique();
            $table->string('name');
            $table->string('category', 40);
            $table->text('description')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('location');
            $table->string('responsible_person')->nullable();
            $table->string('acquisition_type', 30);
            $table->date('acquisition_date');
            $table->unsignedBigInteger('acquisition_cost_cents');
            $table->string('document_reference')->nullable();
            $table->string('depreciation_method', 30);
            $table->unsignedSmallInteger('useful_life_years')->nullable();
            $table->string('status', 20)->default('active');
            $table->date('disposed_at')->nullable();
            $table->unsignedBigInteger('disposal_proceeds_cents')->nullable();
            $table->text('disposal_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name');
            $table->foreignId('disposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disposed_by_name')->nullable();
            $table->timestamps();

            $table->index(['status', 'inventory_number']);
            $table->index(['category', 'status']);
            $table->index('acquisition_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('inventory_sequences');
    }
};
