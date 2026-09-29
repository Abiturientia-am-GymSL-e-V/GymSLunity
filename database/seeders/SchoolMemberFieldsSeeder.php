<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * School alumni fields (graduation, year, former student) as custom member
 * fields. New installations no longer create them; the demo data and tests
 * of a school club add them here.
 */
class SchoolMemberFieldsSeeder extends Seeder
{
    public function run(): void
    {
        $option = fn (string $value): array => ['value' => $value, 'label' => $value, 'active' => true];
        $fields = [
            ['key' => 'custom_graduation', 'label' => 'Abschluss', 'type' => 'select', 'position' => 50, 'max_length' => 80,
                'options' => array_map($option, ['Abitur', 'Fachabitur', 'Mittlere Reife'])],
            ['key' => 'custom_graduation_year', 'label' => 'Jahrgang', 'type' => 'number', 'position' => 60, 'max_length' => 255,
                'options' => [], 'filterable' => true, 'show_in_table' => true],
            ['key' => 'custom_is_former_student', 'label' => 'Ehemalige Schülerin / ehemaliger Schüler', 'type' => 'boolean', 'position' => 80, 'max_length' => 255,
                'options' => []],
        ];
        foreach ($fields as $field) {
            DB::table('member_field_definitions')->insertOrIgnore([
                'filterable' => false, 'show_in_table' => false, ...$field,
                'section' => 'membership', 'required' => false, 'is_custom' => true,
                'options' => json_encode($field['options'], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
