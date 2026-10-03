<?php

declare(strict_types=1);

namespace App\Demo;

use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\MemberAssignment;
use App\Models\MemberFieldDefinition;
use App\Models\User;
use App\Support\Clock;
use Carbon\CarbonImmutable;
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

    public function __construct(private readonly DemoModules $modules) {}

    public function seed(): void
    {
        DB::transaction(function (): void {
            $this->seedClub();
            $this->seedUsers();
            $this->seedMembers();
            $this->seedAssignments();
            $this->modules->seed(User::query()->where('email', DemoAccounts::USERS[0]['email'])->firstOrFail());
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
            // ISO code, as the XRechnung requires it.
            'country' => 'DE',
            'email' => 'info@example.org',
            'phone' => '+49 1234 567890',
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
            'donation_purpose_codes' => ['52-21', '52-4'],
            'contributions_tax_deductible' => true,
            'tax_privilege_notice_type' => 'exemption_notice',
            'tax_privilege_notice_date' => Clock::today()->subMonths(10)->toDateString(),
            'tax_privilege_assessment_period' => (Clock::today()->year - 3).'–'.(Clock::today()->year - 1),
            'tax_privilege_notice_location' => 'Musterstadt',
            'certificate_machine_generated_notified' => true,
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
                // Board members (the first eight) are adults; two birthdays fall into the coming week.
                'birth_date' => $today->subYears(($i < 8 ? 28 : 7) + ($i * 7) % 50)->addDays($i < 2 ? 2 + $i * 3 : $i * 11)->toDateString(),
                'membership_type' => $i % 5 === 4 ? 'Fördermitglied' : 'Aktiv/ordentliches Mitglied',
                'sponsor_contribution' => $i % 5 === 4 ? '120.00' : null,
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

    /**
     * Board and offices with a predecessor, a vacant mandatory office,
     * departments with a change and a few honors, so the overviews for
     * offices, departments and honors have something to show.
     */
    private function seedAssignments(): void
    {
        $office = fn (string $value, string $label, bool $board, ?int $max = 1): array => ['value' => $value, 'label' => $label, 'active' => true, 'board' => $board, 'mandatory' => $board, 'max_holders' => $max];
        // The board comes first in lists and overviews.
        MemberFieldDefinition::query()->where('key', 'club_role')->firstOrFail()->update(['label' => 'Vorstand und Ämter im Hauptverein', 'position' => 1, 'options' => [
            $office('1. Vorsitz', '1. Vorsitz', true), $office('2. Vorsitz', '2. Vorsitz', true), $office('Kasse', 'Kasse', true),
            $office('Schriftführung', 'Schriftführung', true), $office('Kassenprüfung', 'Kassenprüfung', false, 2),
        ]]);
        MemberFieldDefinition::query()->where('key', 'department_role')->firstOrFail()->update(['allow_multiple' => true, 'options' => [
            $office('Abteilungsleitung', 'Abteilungsleitung', false), $office('Übungsleitung', 'Übungsleitung', false, null),
            $office('Jugendwart', 'Jugendwart', false),
        ]]);
        $position = (int) MemberFieldDefinition::query()->max('position');
        $option = fn (string $label, bool $repeatable = false, ?int $jubilee = null): array => ['value' => $label, 'label' => $label, 'active' => true, ...($repeatable ? ['repeatable' => true] : []), ...($jubilee ? ['jubilee_years' => $jubilee] : [])];
        foreach ([
            ['custom_demo_abteilung', 'Abteilungen', 'department', [$option('Turnen'), $option('Fußball'), $option('Leichtathletik'), $option('Tischtennis')]],
            ['custom_demo_ehrung', 'Vereinsehrungen', 'honor', [$option('Ehrennadel Silber', jubilee: 10), $option('Ehrennadel Gold', jubilee: 25), $option('Ehrenurkunde', true)]],
        ] as [$key, $label, $type, $options]) {
            MemberFieldDefinition::query()->create([
                'key' => $key, 'label' => $label, 'type' => $type, 'section' => MemberFieldDefinition::ASSIGNMENT_SECTION,
                'position' => $position += 10, 'is_active' => true, 'is_custom' => true, 'required' => false, 'filterable' => true,
                'show_in_table' => $type === 'department', 'selfservice_visible' => true, 'selfservice_editable' => false,
                'allow_multiple' => false, 'max_length' => 255, 'options' => $options,
            ]);
        }
        ClubSetting::query()->whereKey(1)->increment('fields_version');

        $members = Member::query()->orderBy('member_number')->get()->values();
        $assign = function (int $i, string $field, string $option, ?CarbonImmutable $start, ?CarbonImmutable $end = null, ?string $note = null) use ($members): void {
            MemberAssignment::query()->create([
                'member_id' => $members[$i]->id, 'field_key' => $field, 'option_value' => $option,
                'starts_on' => $start?->toDateString(), 'ends_on' => $end?->toDateString(), 'note' => $note,
            ]);
        };
        $joined = fn (int $i): CarbonImmutable => CarbonImmutable::parse($members[$i]->joined_at);
        $left = fn (int $i): CarbonImmutable => CarbonImmutable::parse($members[$i]->left_at);

        // Oskar led the club until he left, Anna followed him. The secretary post is vacant.
        $assign(37, 'club_role', '1. Vorsitz', $joined(37)->addYears(2), $left(37));
        $assign(0, 'club_role', '1. Vorsitz', $left(37)->addDay());
        $assign(1, 'club_role', '2. Vorsitz', $joined(1));
        $assign(4, 'club_role', 'Kasse', $joined(4));
        $assign(33, 'club_role', 'Kassenprüfung', $joined(33)->addYear(), $left(33));
        $assign(7, 'club_role', 'Kassenprüfung', $joined(7));
        $assign(2, 'department_role', 'Abteilungsleitung', $joined(2), note: 'Turnen');
        $assign(3, 'department_role', 'Übungsleitung', $joined(3));
        $assign(6, 'department_role', 'Übungsleitung', $joined(6));
        $assign(6, 'department_role', 'Jugendwart', $joined(6)->addMonths(6));

        $departments = ['Turnen', 'Fußball', 'Leichtathletik', 'Tischtennis'];
        foreach ($members as $i => $member) {
            if ($i % 5 === 4) {
                continue;
            }
            $department = $departments[$i % 4];
            $end = $member->left_at ? $left($i) : null;
            if ($i >= 10 && $i % 7 === 3) {
                // Changed the department after a year.
                $assign($i, 'custom_demo_abteilung', $department, $joined($i), $joined($i)->addYear());
                $assign($i, 'custom_demo_abteilung', $departments[($i + 1) % 4], $joined($i)->addYear()->addDay(), $end);

                continue;
            }
            $assign($i, 'custom_demo_abteilung', $department, $joined($i), $end);
            if ($i % 6 === 0) {
                $assign($i, 'custom_demo_abteilung', $departments[($i + 2) % 4], $joined($i)->addMonths(3), $end);
            }
        }

        $assign(39, 'custom_demo_ehrung', 'Ehrennadel Silber', $joined(39)->addYears(10));
        $assign(29, 'custom_demo_ehrung', 'Ehrenurkunde', $joined(29)->addYears(5), note: 'Ehrenmitgliedschaft');
        $assign(37, 'custom_demo_ehrung', 'Ehrenurkunde', $left(37), note: 'Dank für die Jahre im Vorsitz');
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
