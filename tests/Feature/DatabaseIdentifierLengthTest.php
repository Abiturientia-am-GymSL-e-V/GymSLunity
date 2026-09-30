<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * MariaDB and MySQL reject identifiers longer than 64 characters, SQLite
 * does not. Tests run on SQLite, so check the generated names here.
 */
class DatabaseIdentifierLengthTest extends TestCase
{
    use RefreshDatabase;

    private const MAX_LENGTH = 64;

    public function test_all_identifiers_fit_mariadb_and_mysql(): void
    {
        $tooLong = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            $names = [$table, ...Schema::getColumnListing($table)];
            foreach (Schema::getIndexes($table) as $index) {
                $names[] = $index['name'];
            }
            // SQLite does not keep foreign key names; rebuild Laravel's default.
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $names[] = str_replace(['-', '.'], '_', strtolower($table.'_'.implode('_', $foreignKey['columns']).'_foreign'));
            }

            foreach ($names as $name) {
                if (strlen($name) > self::MAX_LENGTH) {
                    $tooLong[] = $table.': '.$name.' ('.strlen($name).')';
                }
            }
        }

        $this->assertSame([], $tooLong, 'Bezeichner länger als '.self::MAX_LENGTH.' Zeichen');
    }
}
