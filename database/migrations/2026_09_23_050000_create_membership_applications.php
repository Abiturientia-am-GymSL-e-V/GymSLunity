<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained()->restrictOnDelete();
            $table->string('membership_type', 80);
            $table->timestamp('submitted_at');
            $table->timestamp('approved_at')->nullable()->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approved_by_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_applications');
    }
};
