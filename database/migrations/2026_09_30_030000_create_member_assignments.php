<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MariaDB/MySQL cannot roll back DDL: after a failed earlier run the
        // column and table may already exist without the migration counting as done.
        if (! Schema::hasColumn('member_field_definitions', 'allow_multiple')) {
            Schema::table('member_field_definitions', function (Blueprint $table) {
                // Only meaningful for office fields: several offices of the same field at once.
                $table->boolean('allow_multiple')->default(false)->after('selfservice_editable');
            });
        }
        Schema::dropIfExists('member_assignments');

        // Department, office and honor fields keep their values here instead of
        // in members. Field and option are referenced by key: the configuration
        // restore replaces field definitions wholesale, so a foreign key would break it.
        Schema::create('member_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->string('field_key', 80);
            $table->string('option_value');
            // NULL = beginning unknown (legacy data), inclusive like ends_on.
            $table->date('starts_on')->nullable();
            // NULL = open, the assignment is current or upcoming.
            $table->date('ends_on')->nullable();
            $table->string('note')->nullable();
            $table->string('source', 20)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['member_id', 'field_key']);
            // Explicit name: the generated one exceeds the 64 characters MariaDB/MySQL allow.
            $table->index(['field_key', 'option_value', 'starts_on', 'ends_on'], 'member_assignments_option_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_assignments');
        Schema::table('member_field_definitions', fn (Blueprint $table) => $table->dropColumn('allow_multiple'));
    }
};
