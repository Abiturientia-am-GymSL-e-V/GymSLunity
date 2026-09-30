<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Installations with DB_CONNECTION=mariadb kept a BLOB column of at most
     * 64 KB for member documents: the widening only checked the mysql driver.
     */
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE member_documents MODIFY contents LONGBLOB NOT NULL');
        }
    }

    public function down(): void
    {
        // Narrowing again could truncate stored documents.
    }
};
