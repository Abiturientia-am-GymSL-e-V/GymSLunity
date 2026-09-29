<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClubSetting;
use App\Models\Contribution;
use App\Models\Donation;
use App\Models\Member;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('memberOverview', null)
            ->where('birthdays', null)
            ->where('contributionOverview', null)
            ->where('donationOverview', null));
    }

    public function test_administrator_sees_current_members_birthdays_contributions_and_donations(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00'));
        $user = User::factory()->create(['roles' => ['admin']]);
        $this->actingAs($user);

        $first = Member::factory()->create([
            'first_name' => 'Anna', 'last_name' => 'Alt', 'membership_type' => 'Aktiv',
            'joined_at' => '2026-01-10', 'birth_date' => '1990-09-10',
        ]);
        Member::factory()->create([
            'first_name' => 'Ben', 'last_name' => 'Heute', 'membership_type' => 'Aktiv',
            'joined_at' => '2025-01-01', 'birth_date' => '2000-09-23',
        ]);
        Member::factory()->create([
            'first_name' => 'Clara', 'last_name' => 'Bald', 'membership_type' => 'Passiv',
            'joined_at' => '2026-03-01', 'birth_date' => '1985-10-07',
        ]);
        Member::factory()->create([
            'first_name' => 'Dora', 'last_name' => 'Später', 'membership_type' => 'Aktiv',
            'joined_at' => '2025-01-01', 'birth_date' => '1995-10-08',
        ]);
        Member::factory()->create([
            'first_name' => 'Ehemaliges', 'last_name' => 'Mitglied', 'membership_type' => 'Aktiv',
            'joined_at' => '2025-01-01', 'left_at' => '2026-09-01', 'birth_date' => '1995-09-23',
        ]);
        Member::factory()->create([
            'first_name' => 'Verstorbenes', 'last_name' => 'Mitglied', 'membership_type' => 'Aktiv',
            'joined_at' => '2025-01-01', 'deceased_at' => '2026-08-01', 'birth_date' => '1995-09-23',
        ]);
        Member::factory()->create([
            'first_name' => 'Zukünftiges', 'last_name' => 'Mitglied', 'membership_type' => 'Aktiv',
            'joined_at' => '2026-10-01', 'birth_date' => '1995-09-23',
        ]);

        Contribution::query()->create([
            'account_id' => $first->contributionAccount->id,
            'kind' => 'contribution', 'description' => 'Jahresbeitrag 2026',
            'amount_cents' => 10000, 'paid_cents' => 4000,
            'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
            'due_date' => '2026-09-01', 'status' => 'open',
        ]);
        Contribution::query()->create([
            'account_id' => $first->contributionAccount->id,
            'kind' => 'contribution', 'description' => 'Zusatzbeitrag 2026',
            'amount_cents' => 8000, 'paid_cents' => 8000,
            'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
            'due_date' => '2026-10-01', 'status' => 'paid',
        ]);
        Contribution::query()->create([
            'account_id' => $first->contributionAccount->id,
            'kind' => 'contribution', 'description' => 'Restbeitrag 2025',
            'amount_cents' => 5000, 'paid_cents' => 0,
            'period_start' => '2025-01-01', 'period_end' => '2025-12-31',
            'due_date' => '2025-12-01', 'status' => 'open',
        ]);

        $this->createDonation('SP-2026-000001', 'Erste Spenderin', 5000, '2026-05-10');
        $this->createDonation('SP-2026-000002', 'Letzter Spender', 2500, '2026-09-20');
        $this->createDonation('SP-2025-000001', 'Alter Spender', 10000, '2025-12-20');

        $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('today', '2026-09-23')
            ->where('memberOverview.current_count', 4)
            ->where('memberOverview.joined_this_year', 2)
            ->where('memberOverview.left_this_year', 1)
            ->where('memberOverview.by_membership_type.0', ['label' => 'Aktiv', 'count' => 3])
            ->where('memberOverview.by_membership_type.1', ['label' => 'Passiv', 'count' => 1])
            ->has('birthdays', 3)
            ->where('birthdays.0.name', 'Anna Alt')
            ->where('birthdays.0.days_from_today', -13)
            ->where('birthdays.1.name', 'Ben Heute')
            ->where('birthdays.1.days_from_today', 0)
            ->where('birthdays.2.name', 'Clara Bald')
            ->where('birthdays.2.days_from_today', 14)
            ->where('contributionOverview.assessed_count', 2)
            ->where('contributionOverview.assessed_cents', 18000)
            ->where('contributionOverview.paid_cents', 12000)
            ->where('contributionOverview.collection_rate', 67)
            ->where('contributionOverview.open_count', 2)
            ->where('contributionOverview.open_cents', 11000)
            ->where('contributionOverview.overdue_count', 2)
            ->where('donationOverview.count', 2)
            ->where('donationOverview.amount_cents', 7500)
            ->where('donationOverview.average_cents', 3750)
            ->where('donationOverview.open_certificate_count', 2)
            ->where('donationOverview.latest.donor_name', 'Letzter Spender'));
    }

    public function test_year_figures_follow_a_financial_year_starting_in_july(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00'));
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'fiscal_year_start' => '7']]);
        $this->actingAs(User::factory()->create(['roles' => ['admin']]));

        $member = Member::factory()->create(['joined_at' => '2026-08-01']);
        Member::factory()->create(['joined_at' => '2026-03-01']);
        foreach (['2026-06-30' => 3000, '2026-07-01' => 5000, '2027-06-30' => 7000] as $due => $amount) {
            Contribution::query()->create([
                'account_id' => $member->contributionAccount->id, 'kind' => 'contribution', 'description' => 'Beitrag',
                'amount_cents' => $amount, 'paid_cents' => 0, 'period_start' => $due, 'period_end' => $due,
                'due_date' => $due, 'status' => 'open',
            ]);
        }

        $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('memberOverview.year', '2026/27')
            ->where('memberOverview.joined_this_year', 1)
            ->where('contributionOverview.year', '2026/27')
            ->where('contributionOverview.assessed_cents', 12000));
    }

    public function test_birthday_window_works_across_the_turn_of_the_year(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-01-02 12:00:00'));
        $this->actingAs(User::factory()->create(['roles' => ['admin']]));
        Member::factory()->create([
            'first_name' => 'Dezember', 'last_name' => 'Geburtstag',
            'birth_date' => '1990-12-25',
        ]);
        Member::factory()->create([
            'first_name' => 'Januar', 'last_name' => 'Geburtstag',
            'birth_date' => '1990-01-16',
        ]);

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->has('birthdays', 2)
            ->where('birthdays.0.name', 'Dezember Geburtstag')
            ->where('birthdays.0.days_from_today', -8)
            ->where('birthdays.1.name', 'Januar Geburtstag')
            ->where('birthdays.1.days_from_today', 14));
    }

    public function test_dashboard_only_exposes_sections_allowed_for_the_user(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00'));
        Member::factory()->create(['birth_date' => '1990-09-23']);
        $this->actingAs(User::factory()->create(['roles' => ['bv']]));

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('memberOverview', null)
            ->where('birthdays', null)
            ->has('contributionOverview')
            ->where('donationOverview', null));
    }

    private function createDonation(string $number, string $name, int $amountCents, string $date): Donation
    {
        return Donation::query()->create([
            'receipt_number' => $number,
            'created_by' => null,
            'created_by_name' => 'Test Admin',
            'donor_name' => $name,
            'donor_street' => 'Musterweg 1',
            'donor_postal_code' => '10115',
            'donor_city' => 'Berlin',
            'donor_country' => 'DE',
            'donor_email' => null,
            'donation_type' => 'money',
            'amount_cents' => $amountCents,
            'donated_at' => $date,
            'purpose_code' => '52-21',
            'purpose_label' => 'Förderung des Sports',
            'description' => null,
            'expense_waiver' => false,
            'created_at' => now(),
        ]);
    }
}
