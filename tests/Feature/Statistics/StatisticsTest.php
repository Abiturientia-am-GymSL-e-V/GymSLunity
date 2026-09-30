<?php

declare(strict_types=1);

namespace Tests\Feature\Statistics;

use App\Models\ClubSetting;
use App\Models\Contribution;
use App\Models\Donation;
use App\Models\Member;
use App\Models\MemberFieldDefinition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StatisticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00'));
        $this->actingAs(User::factory()->create(['roles' => ['admin']]));
    }

    public function test_statistics_aggregate_members_finances_and_data_quality(): void
    {
        $complete = Member::factory()->create([
            'membership_type' => 'Aktiv',
            'joined_at' => '2026-01-10',
            'birth_date' => '1990-05-10',
            'gender' => 'w',
            'street' => 'Musterweg 1',
            'city' => 'Berlin',
            'country' => 'Deutschland',
            'payment_method' => 'SEPA-Lastschrift',
            'iban' => 'DE89370400440532013000',
            'mandate_reference' => 'M-100',
            'mandate_signed_at' => '2026-01-10',
        ]);
        Member::factory()->create([
            'membership_type' => 'Fördermitglied',
            'joined_at' => '2025-01-01',
            'birth_date' => '2010-06-01',
            'gender' => 'm',
            'email' => null,
            'street' => null,
            'payment_method' => null,
        ]);
        Member::factory()->create(['joined_at' => '2025-01-01', 'left_at' => '2026-08-15']);
        Member::factory()->create(['joined_at' => '2025-01-01', 'deceased_at' => '2026-07-15']);
        Member::factory()->create(['joined_at' => '2026-10-01']);
        Member::factory()->create(['membership_type' => 'Kontakt', 'joined_at' => null]);

        Contribution::query()->create([
            'account_id' => $complete->contributionAccount->id,
            'kind' => 'contribution',
            'description' => 'Jahresbeitrag',
            'amount_cents' => 10000,
            'paid_cents' => 6000,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'due_date' => '2026-09-01',
            'status' => 'open',
        ]);
        Contribution::query()->create([
            'account_id' => $complete->contributionAccount->id,
            'kind' => 'contribution',
            'description' => 'Vorjahr',
            'amount_cents' => 5000,
            'paid_cents' => 0,
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'due_date' => '2025-12-01',
            'status' => 'open',
        ]);
        $this->createDonation('SP-2026-000001', 7500, '2026-04-12');

        $this->get(route('statistics', [
            'from' => '2026-01-01',
            'to' => '2026-09-23',
            'as_of' => '2026-09-23',
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Statistics')
            ->where('activeTab', 'overview')
            ->where('navigationBreadcrumb.title', 'Übersicht')
            ->where('filters.from', '2026-01-01')
            ->where('filters.to', '2026-09-23')
            ->where('summary.active_members', 2)
            ->where('summary.contacts', 1)
            ->where('summary.joined', 1)
            ->where('summary.departed', 2)
            ->where('summary.net_change', -1)
            ->has('memberTrend', 9)
            ->where('memberTrend.0.key', '2026-01')
            ->where('memberTrend.0.active', 4)
            ->where('memberTrend.0.joined', 1)
            ->where('memberTrend.6.departed', 1)
            ->where('memberTrend.7.departed', 1)
            ->where('memberBreakdowns.membership_types.0', ['label' => 'Aktiv', 'count' => 1])
            ->where('memberBreakdowns.membership_types.1', ['label' => 'Fördermitglied', 'count' => 1])
            ->where('finances.contributions.count', 1)
            ->where('finances.contributions.assessed_cents', 10000)
            ->where('finances.contributions.paid_cents', 6000)
            ->where('finances.contributions.open_cents', 4000)
            ->where('finances.contributions.overdue_count', 1)
            ->where('finances.contributions.collection_rate', 60)
            ->where('finances.donations.count', 1)
            ->where('finances.donations.amount_cents', 7500)
            ->where('dataQuality.checks.0.count', 1)
            ->where('dataQuality.checks.3.count', 1)
            ->where('dataQuality.checks.4.count', 1));
    }

    public function test_member_tab_exposes_stock_report_and_csv_export(): void
    {
        Member::factory()->create(['joined_at' => '2020-01-01', 'birth_date' => '1990-05-10', 'gender' => 'w']);
        Member::factory()->create(['joined_at' => '2020-01-01', 'birth_date' => '1990-10-10', 'gender' => 'm']);
        Member::factory()->create(['joined_at' => '2020-01-01', 'birth_date' => null, 'gender' => null]);

        $parameters = ['from' => '2026-01-01', 'to' => '2026-09-23', 'as_of' => '2026-09-23'];
        $this->get(route('statistics.members', $parameters))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('activeTab', 'members')
            ->where('navigationBreadcrumb.title', 'Mitglieder')
            ->has('stockReport', 2)
            ->where('stockReport.0', [
                'birth_year' => 1990,
                'label' => '1990',
                'female' => 1,
                'male' => 1,
                'diverse' => 0,
                'unspecified' => 0,
                'total' => 2,
            ])
            ->where('stockReport.1.label', 'Ohne Geburtsdatum')
            ->where('stockReport.1.unspecified', 1));

        $response = $this->get(route('statistics.stock-csv', $parameters));
        $response->assertOk()->assertDownload('bestandsmeldung-2026-09-23.csv');
        $content = $response->streamedContent();
        $this->assertStringContainsString('Geburtsjahr;Weiblich;Männlich;Divers;"Ohne Angabe";Gesamt', $content);
        $this->assertStringContainsString('1990;1;1;0;0;2', $content);
    }

    public function test_departments_are_counted_on_the_reference_date_and_limit_the_stock_report(): void
    {
        MemberFieldDefinition::query()->create([
            'key' => 'custom_sport', 'label' => 'Sparten', 'type' => 'department', 'section' => MemberFieldDefinition::ASSIGNMENT_SECTION,
            'position' => 1000, 'is_active' => true, 'is_custom' => true, 'required' => false, 'filterable' => true, 'show_in_table' => false,
            'selfservice_visible' => false, 'selfservice_editable' => false, 'allow_multiple' => false, 'max_length' => 255,
            'options' => [['value' => 'gym', 'label' => 'Turnen', 'active' => true], ['value' => 'ball', 'label' => 'Fußball', 'active' => true], ['value' => 'chess', 'label' => 'Schach', 'active' => true]],
        ]);
        $both = Member::factory()->withAssignment('custom_sport', 'gym', '2020-01-01')->withAssignment('custom_sport', 'ball', '2021-01-01')
            ->create(['joined_at' => '2020-01-01', 'birth_date' => '1990-05-10', 'gender' => 'w']);
        Member::factory()->withAssignment('custom_sport', 'gym', '2020-01-01', '2026-06-30')->create(['joined_at' => '2020-01-01', 'birth_date' => '1985-01-01', 'gender' => 'm']);
        // Former members do not count, even with an open department.
        Member::factory()->withAssignment('custom_sport', 'gym', '2020-01-01')->create(['joined_at' => '2020-01-01', 'left_at' => '2025-12-31']);
        $this->assertTrue($both->isCurrentMember());

        $parameters = ['from' => '2026-01-01', 'to' => '2026-09-23', 'as_of' => '2026-09-23'];
        $this->get(route('statistics.members', $parameters))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('departments.0.label', 'Sparten')
            ->where('departments.0.items', [['label' => 'Turnen', 'count' => 1], ['label' => 'Fußball', 'count' => 1], ['label' => 'Ohne Abteilung', 'count' => 1]])
            ->where('departmentChoices.0', ['value' => 'custom_sport:gym', 'label' => 'Turnen'])
            ->has('stockReport', 2));
        $this->get(route('statistics.members', [...$parameters, 'as_of' => '2026-06-30']))->assertInertia(fn (Assert $page) => $page
            ->where('departments.0.items.0', ['label' => 'Turnen', 'count' => 2]));

        $this->get(route('statistics.members', [...$parameters, 'department' => 'custom_sport:gym']))->assertInertia(fn (Assert $page) => $page
            ->where('filters.department', 'custom_sport:gym')
            ->has('stockReport', 1)->where('stockReport.0.birth_year', 1990));
        $content = $this->get(route('statistics.stock-csv', [...$parameters, 'department' => 'custom_sport:gym']))->assertOk()->streamedContent();
        $this->assertStringContainsString('"Bestandsmeldung zum";23.09.2026;Abteilung;Turnen', $content);
        $this->assertStringNotContainsString('1985', $content);
        $this->get(route('statistics.members', [...$parameters, 'department' => 'custom_sport:unknown']))->assertSessionHasErrors('department');
    }

    public function test_finance_status_includes_every_kind_of_charge(): void
    {
        $member = Member::factory()->create(['joined_at' => '2020-01-01']);
        foreach ([
            ['kind' => 'manual_charge', 'description' => 'Manuelle Forderung', 'due_date' => '2026-09-22'],
            ['kind' => 'booking', 'description' => 'Buchungsentgelt', 'due_date' => '2026-09-23'],
        ] as $charge) {
            Contribution::query()->create([
                'account_id' => $member->contributionAccount->id,
                ...$charge,
                'amount_cents' => 500,
                'paid_cents' => 0,
                'period_start' => $charge['due_date'],
                'period_end' => $charge['due_date'],
                'status' => 'open',
            ]);
        }

        $this->get(route('statistics.finances', [
            'from' => '2026-09-01',
            'to' => '2026-09-23',
            'as_of' => '2026-09-23',
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('finances.contributions.count', 2)
            ->where('finances.contributions.assessed_cents', 1000)
            ->where('finances.contributions.open_cents', 1000)
            ->where('finances.contributions.overdue_count', 1)
            ->where('finances.contributions.overdue_cents', 500)
            ->where('finances.monthly.0.contributions_cents', 1000));
    }

    public function test_complete_statistics_report_can_be_downloaded_as_pdf(): void
    {
        Member::factory()->create(['joined_at' => '2020-01-01']);
        $parameters = ['from' => '2026-01-01', 'to' => '2026-09-23', 'as_of' => '2026-09-23'];

        $content = $this->get(route('statistics.pdf', $parameters))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="auswertungen-2026-01-01-bis-2026-09-23.pdf"')
            ->getContent();

        $this->assertStringStartsWith('%PDF-', $content);
    }

    public function test_statistics_reject_periods_longer_than_36_months(): void
    {
        $this->get(route('statistics', [
            'from' => '2020-01-01',
            'to' => '2026-09-23',
            'as_of' => '2026-09-23',
        ]))->assertUnprocessable();
    }

    public function test_quick_periods_offer_the_running_and_previous_financial_year(): void
    {
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'fiscal_year_start' => '7']]);

        $this->get(route('statistics'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('periods.1', ['label' => 'Geschäftsjahr 2026/27', 'from' => '2026-07-01', 'to' => '2026-09-23'])
            ->where('periods.2', ['label' => 'Geschäftsjahr 2025/26', 'from' => '2025-07-01', 'to' => '2026-06-30']));
    }

    private function createDonation(string $number, int $amountCents, string $date): Donation
    {
        return Donation::query()->create([
            'receipt_number' => $number,
            'created_by' => null,
            'created_by_name' => 'Test Admin',
            'donor_name' => 'Testspende',
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
