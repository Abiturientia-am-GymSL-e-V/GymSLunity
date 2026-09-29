<?php

declare(strict_types=1);

namespace App\Demo;

use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\User;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Sample club for the public demo. Uses fixed name lists instead of Faker,
 * which is not part of release packages, and dates relative to today, so
 * the data does not age between two nightly resets.
 */
final class DemoData
{
    private const FIRST_NAMES = [
        'Anna', 'Ben', 'Clara', 'David', 'Emilia', 'Felix', 'Greta', 'Hannes', 'Ida', 'Jonas',
        'Klara', 'Leon', 'Marie', 'Noah', 'Olivia', 'Paul', 'Romy', 'Samuel', 'Thea', 'Vincent',
        'Wilma', 'Yusuf', 'Zoe', 'Anton', 'Berit', 'Carl', 'Dilara', 'Emil', 'Frieda', 'Gustav',
        'Hanna', 'Ilias', 'Jana', 'Karl', 'Lotte', 'Mats', 'Nele', 'Oskar', 'Pia', 'Rasmus',
    ];

    private const LAST_NAMES = [
        'Albers', 'Brandt', 'Conrad', 'Dietrich', 'Engel', 'Franke', 'Graf', 'Hoffmann', 'Jansen', 'Keller',
        'Lange', 'Möller', 'Neumann', 'Otto', 'Peters', 'Roth', 'Schulz', 'Thiel', 'Vogel', 'Winter',
    ];

    private const CITIES = [['12345', 'Musterstadt'], ['12346', 'Beispielhausen'], ['12347', 'Demodorf']];

    private const STREETS = ['Hauptstraße', 'Gartenweg', 'Lindenallee', 'Schulstraße', 'Am Sportplatz'];

    public function seed(): void
    {
        DB::transaction(function (): void {
            $this->seedClub();
            $this->seedUsers();
            $this->seedMembers();
        });
    }

    private function seedClub(): void
    {
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data,
            'name' => 'Turnverein Musterstadt e. V.',
            'short_name' => 'TV Musterstadt',
            'form_of_address' => 'du',
            'founded_at' => '1908-05-01',
            'fiscal_year_start' => '1',
            'street' => 'Am Sportplatz 1',
            'postal_code' => '12345',
            'city' => 'Musterstadt',
            'country' => 'Deutschland',
            'email' => 'info@example.org',
            'website' => 'https://example.org',
            'register_number' => 'VR 12345',
            'register_court' => 'Amtsgericht Musterstadt',
            'tax_number' => '12/345/67890',
            'tax_office' => 'Finanzamt Musterstadt',
            'is_nonprofit' => true,
            'representatives' => 'Anna Albers (1. Vorsitzende), Ben Brandt (2. Vorsitzender)',
            'account_holder' => 'Turnverein Musterstadt e. V.',
            'iban' => self::iban(1),
            'bic' => 'COBADEFFXXX',
            'bank_name' => 'Demobank Musterstadt',
            'creditor_id' => 'DE98ZZZ09999999999',
            'selfservice_enabled' => true,
            'public_join_enabled' => true,
        ]]);
    }

    private function seedUsers(): void
    {
        foreach (DemoAccounts::USERS as $account) {
            $user = new User(['name' => $account['name'], 'email' => $account['email'], 'password' => (string) config('demo.password')]);
            $user->forceFill(['roles' => $account['roles'], 'email_verified_at' => now()])->save();
        }
    }

    private function seedMembers(): void
    {
        $today = Clock::today();
        foreach (self::FIRST_NAMES as $i => $firstName) {
            $lastName = self::LAST_NAMES[$i % count(self::LAST_NAMES)];
            [$postalCode, $city] = self::CITIES[$i % count(self::CITIES)];
            $sepa = $i % 3 !== 2;
            $joinedAt = $today->subDays(45 + $i * 97);
            Member::query()->create([
                'member_number' => 1001 + $i,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'gender' => $i % 2 === 0 ? 'w' : 'm',
                'email' => $i === 0 ? DemoAccounts::MEMBER_EMAIL : strtolower(self::ascii($firstName).'.'.self::ascii($lastName)).'@example.org',
                'street' => self::STREETS[$i % count(self::STREETS)].' '.($i + 1),
                'postal_code' => $postalCode,
                'city' => $city,
                'country' => 'Deutschland',
                // Two birthdays fall into the coming week for the dashboard and calendar.
                'birth_date' => $today->subYears(8 + ($i * 7) % 70)->addDays($i < 2 ? 2 + $i * 3 : $i * 11)->toDateString(),
                'membership_type' => $i % 5 === 4 ? 'Fördermitglied' : 'Aktiv/ordentliches Mitglied',
                'club_role' => [0 => '1. Vorsitzende', 1 => '2. Vorsitzender', 4 => 'Kassenwartin', 7 => 'Kassenprüfer'][$i] ?? null,
                'is_honorary' => $i === 29,
                'joined_at' => $joinedAt->toDateString(),
                'left_at' => in_array($i, [33, 37], true) ? $today->subDays(20 + $i)->toDateString() : null,
                'payment_method' => $sepa ? 'SEPA-Lastschrift' : 'Überweisung',
                'iban' => $sepa ? self::iban(100 + $i) : null,
                'mandate_reference' => $sepa ? sprintf('TVM-%04d', 1001 + $i) : null,
                'mandate_signed_at' => $sepa ? $joinedAt->toDateString() : null,
                'mandate_type' => 'recurring',
            ]);
        }
    }

    private static function ascii(string $value): string
    {
        return strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue']);
    }

    /** Valid German IBAN of the fictitious bank code 37040044. */
    private static function iban(int $account): string
    {
        $bban = '37040044'.sprintf('%010d', $account);
        $remainder = 0;
        foreach (str_split($bban.'131400') as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }
        $checksum = 98 - $remainder;

        return sprintf('DE%02d%s', $checksum, $bban);
    }
}
