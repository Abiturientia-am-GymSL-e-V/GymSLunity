<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('member_number')->unique();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('mobile_phone', 50)->nullable();
            $table->string('street')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->date('birth_date')->nullable();
            $table->unsignedSmallInteger('graduation_year')->nullable()->index();
            $table->string('graduation', 80)->nullable();
            $table->string('membership_type', 80)->default('Kontakt')->index();
            $table->string('department_role', 100)->nullable()->index();
            $table->string('club_role', 100)->nullable()->index();
            $table->boolean('is_honorary')->default(false);
            // Refers to former pupils, not to terminated memberships.
            $table->boolean('is_former_student')->default(false);
            $table->date('joined_at')->nullable();
            $table->date('left_at')->nullable();
            $table->date('deceased_at')->nullable();
            $table->timestamps();
            $table->index(['last_name', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
