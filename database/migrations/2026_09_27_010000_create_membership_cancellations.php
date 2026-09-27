<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->date('exit_date')->nullable();
            $table->timestamp('confirmed_at')->nullable()->index();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('confirmed_by_name')->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->string('email_error')->nullable();
            $table->index(['member_id', 'confirmed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_cancellations');
    }
};
