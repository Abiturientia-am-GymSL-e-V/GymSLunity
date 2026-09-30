<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the columns members.department_role and members.club_role. Since
 * the office migration (2026_09_30_040000) their values live in
 * member_assignments; the columns were kept for one release as a fallback
 * and are no longer read or written.
 */
return new class extends Migration
{
    private const KEYS = ['department_role', 'club_role'];

    public function up(): void
    {
        $columns = array_values(array_filter(self::KEYS, fn (string $key): bool => Schema::hasColumn('members', $key)));
        if ($columns === []) {
            return;
        }
        // SQLite cannot drop an indexed column, so the indexes go first.
        Schema::table('members', function (Blueprint $table) use ($columns): void {
            foreach ($columns as $column) {
                $table->dropIndex([$column]);
            }
        });
        Schema::table('members', fn (Blueprint $table) => $table->dropColumn($columns));
    }

    /**
     * Restores the columns with the values taken over by the office
     * migration, as far as those assignments still exist. That way the office
     * migration can be rolled back afterwards as well.
     */
    public function down(): void
    {
        $missing = array_values(array_filter(self::KEYS, fn (string $key): bool => ! Schema::hasColumn('members', $key)));
        if ($missing === []) {
            return;
        }
        Schema::table('members', function (Blueprint $table) use ($missing): void {
            foreach ($missing as $column) {
                $table->string($column, 100)->nullable()->index();
            }
        });
        DB::transaction(function () use ($missing): void {
            $assignments = DB::table('member_assignments')->whereIn('field_key', $missing)->where('source', 'migration')->orderBy('id')->get(['member_id', 'field_key', 'option_value']);
            foreach ($assignments as $assignment) {
                DB::table('members')->where('id', $assignment->member_id)->update([$assignment->field_key => mb_substr((string) $assignment->option_value, 0, 100)]);
            }
        });
    }
};
