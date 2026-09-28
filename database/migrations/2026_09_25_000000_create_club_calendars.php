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
        Schema::create('club_calendars', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color', 7);
            $table->string('type', 20)->default('custom');
            $table->string('public_token', 64)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('club_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_calendar_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('all_day')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['starts_at', 'ends_at']);
        });

        Schema::create('club_calendar_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_calendar_id')->constrained()->cascadeOnDelete();
            $table->string('field_key', 80);
            $table->string('value');
            $table->timestamps();
            $table->unique(['club_calendar_id', 'field_key', 'value']);
        });

        Schema::create('member_calendar_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamps();
        });

        DB::table('club_calendars')->insert([
            ['name' => 'Geburtstage', 'color' => '#db2777', 'type' => 'birthdays', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Allgemeiner Vereinskalender', 'color' => '#2563eb', 'type' => 'general', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('member_calendar_tokens');
        Schema::dropIfExists('club_calendar_rules');
        Schema::dropIfExists('club_calendar_events');
        Schema::dropIfExists('club_calendars');
    }
};
