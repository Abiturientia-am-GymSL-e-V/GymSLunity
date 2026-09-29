<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Member;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoMembersSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Mustermitglieder dürfen nur lokal oder in Tests angelegt werden.');
        }
        if (Member::query()->exists()) {
            $this->command->info('Mitglieder vorhanden; es wurden keine Musterdaten angelegt oder verändert.');

            return;
        }

        $this->call(SchoolMemberFieldsSeeder::class);
        $names = ['Anna', 'Ben', 'Clara', 'David', 'Emilia', 'Felix', 'Greta', 'Hannes', 'Ida', 'Jonas', 'Klara', 'Leon', 'Mia', 'Noah', 'Olivia', 'Paul', 'Romy', 'Samuel', 'Thea', 'Vincent'];
        $memberships = ['Kontakt', 'Aktive Schüler', 'Ehemalige', 'Fördermitglieder', 'Externe Mitglieder', 'Lehrer'];
        $cities = [['12345', 'Musterstadt'], ['01234', 'Beispielhausen'], ['23456', 'Demodorf']];

        DB::transaction(function () use ($names, $memberships, $cities) {
            foreach ($names as $i => $name) {
                Member::query()->create([
                    'member_number' => 9000001 + $i,
                    'first_name' => $name,
                    'last_name' => 'Mustermitglied '.($i + 1),
                    'email' => 'mitglied'.($i + 1).'@example.invalid',
                    'street' => 'Musterstraße '.($i + 1),
                    'postal_code' => $cities[$i % 3][0],
                    'city' => $cities[$i % 3][1],
                    'country' => 'Deutschland',
                    'birth_date' => sprintf('%04d-%02d-%02d', 1970 + $i * 2, 1 + $i % 12, 1 + $i % 27),
                    'custom_values' => ['custom_graduation_year' => 2000 + $i, 'custom_graduation' => $i % 2 ? 'Abitur' : null, 'custom_is_former_student' => $i % 6 === 2],
                    'membership_type' => $memberships[$i % 6],
                    'department_role' => [0 => '1. Vorsitzender', 1 => '2. Vorsitzender', 7 => 'Delegierte'][$i] ?? null,
                    'club_role' => [0 => '1. Vorsitzender', 4 => 'Kassierer', 12 => 'Kassenprüfer'][$i] ?? null,
                    'is_honorary' => $i === 4,
                    'joined_at' => $i % 6 === 0 ? null : '2025-01-01',
                ]);
            }
        });
    }
}
