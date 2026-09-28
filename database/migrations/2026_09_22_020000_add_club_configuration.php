<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_settings', function (Blueprint $table) {
            $table->id();
            $table->json('data');
            $table->unsignedInteger('version')->default(0);
            $table->unsignedInteger('fields_version')->default(0);
            $table->timestamps();
        });
        DB::table('club_settings')->insert(['id' => 1, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('member_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('label', 120);
            $table->string('type', 20);
            $table->string('section', 30);
            $table->unsignedInteger('position');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_custom')->default(false);
            $table->boolean('required')->default(false);
            $table->boolean('filterable')->default(false);
            $table->boolean('show_in_table')->default(false);
            $table->json('options');
            $table->unsignedInteger('max_length')->default(255);
            $table->timestamps();
        });
        $legacy = ['graduation', 'graduation_year', 'is_former_student'];
        $fields = File::json(database_path('data/member-fields-v1.json'), JSON_THROW_ON_ERROR);
        foreach ($fields as $field) {
            if ($field['key'] === 'membership_type') {
                $choices = array_unique(['Aktiv/ordentliches Mitglied', 'Fördermitglied', ...DB::table('members')->distinct()->pluck('membership_type')->all()]);
                $field['options'] = array_map(fn ($value) => ['value' => $value, 'label' => $value, 'active' => true], array_values($choices));
            }
            $custom = in_array($field['key'], $legacy, true);
            DB::table('member_field_definitions')->insert([
                'key' => $custom ? 'custom_'.$field['key'] : $field['key'], 'label' => $field['label'], 'type' => $field['type'],
                'section' => $field['section'], 'position' => $field['position'], 'required' => $field['required'],
                'is_custom' => $custom, 'filterable' => $field['key'] === 'graduation_year', 'show_in_table' => $field['key'] === 'graduation_year',
                'options' => json_encode($field['options'], JSON_THROW_ON_ERROR), 'max_length' => $field['max'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('member_field_definitions')->insert([
            'key' => 'payment_method', 'label' => 'Zahlungsart', 'type' => 'select', 'section' => 'bank', 'position' => 5,
            'options' => json_encode(array_map(fn ($value) => ['value' => $value, 'label' => $value, 'active' => true], ['SEPA-Lastschrift', 'Überweisung', 'Bar', 'Sonstiges']), JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Schema::table('members', function (Blueprint $table) {
            $table->string('payment_method')->nullable();
            $table->json('custom_values')->nullable();
        });
        DB::table('members')->orderBy('id')->chunkById(200, function ($members) use ($legacy) {
            foreach ($members as $member) {
                $values = [];
                foreach ($legacy as $key) {
                    $values['custom_'.$key] = $key === 'is_former_student' ? (bool) $member->{$key} : $member->{$key};
                }
                DB::table('members')->where('id', $member->id)->update(['custom_values' => json_encode($values, JSON_THROW_ON_ERROR)]);
            }
        });
        Schema::table('members', function (Blueprint $table) use ($legacy) {
            $table->dropIndex('members_graduation_year_index');
            $table->dropColumn($legacy);
        });
        Schema::table('member_changes', fn (Blueprint $table) => $table->json('field_schema')->nullable());
        // Keep earlier snapshots readable after the three school columns move to JSON.
        DB::table('member_changes')->orderBy('id')->chunkById(200, function ($changes) use ($legacy) {
            foreach ($changes as $change) {
                $update = [];
                foreach (['before', 'after'] as $side) {
                    $snapshot = json_decode($change->{$side}, true, flags: JSON_THROW_ON_ERROR);
                    foreach ($legacy as $key) {
                        if (array_key_exists($key, $snapshot)) {
                            $snapshot['custom_'.$key] = $snapshot[$key];
                            unset($snapshot[$key]);
                        }
                    }
                    $update[$side] = json_encode($snapshot, JSON_THROW_ON_ERROR);
                }
                $update['changed_fields'] = json_encode(array_map(fn ($key) => in_array($key, $legacy, true) ? 'custom_'.$key : $key, json_decode($change->changed_fields, true, flags: JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
                DB::table('member_changes')->where('id', $change->id)->update($update);
            }
        });
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
        });
        Schema::create('configuration_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('subject');
            $table->json('before');
            $table->json('after');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Diese Datenmigration wird nicht automatisch zurückgerollt. Für eine Rückkehr zum alten Schema das zugehörige Datenbankbackup wiederherstellen.');
    }
};
