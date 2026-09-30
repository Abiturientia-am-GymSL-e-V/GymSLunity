<?php

declare(strict_types=1);

namespace Tests\Feature\Members;

use App\Communication\CommunicationTemplate;
use App\Models\Member;
use App\Models\MemberAssignment;
use App\Models\MemberFieldDefinition;
use App\Models\User;
use App\SelfService\FormTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AssignmentOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-06-15 12:00:00');
        // The default office fields would appear alongside the fields of these tests.
        MemberFieldDefinition::query()->whereIn('key', ['department_role', 'club_role'])->update(['is_active' => false]);
    }

    /** @param list<array<string, mixed>> $options */
    private function field(string $key, string $type, array $options, array $overrides = []): MemberFieldDefinition
    {
        return MemberFieldDefinition::query()->create([
            'key' => $key, 'label' => ucfirst($type), 'type' => $type,
            'section' => MemberFieldDefinition::ASSIGNMENT_SECTION, 'position' => 1000, 'is_active' => true, 'is_custom' => true,
            'required' => false, 'filterable' => false, 'show_in_table' => false, 'selfservice_visible' => false,
            'selfservice_editable' => false, 'allow_multiple' => true, 'max_length' => 255,
            'options' => array_map(fn (array $option): array => ['active' => true, ...$option], $options),
            ...$overrides,
        ]);
    }

    private function board(): MemberFieldDefinition
    {
        return $this->field('custom_board', 'office', [
            ['value' => 'chair', 'label' => '1. Vorsitz', 'board' => true, 'mandatory' => true, 'max_holders' => 1],
            ['value' => 'treasurer', 'label' => 'Kasse', 'board' => true, 'mandatory' => true],
            ['value' => 'auditor', 'label' => 'Kassenprüfung', 'board' => false, 'max_holders' => 1],
        ]);
    }

    private function assign(Member $member, string $field, string $option, ?string $from, ?string $to = null, ?string $note = null): void
    {
        MemberAssignment::query()->create([
            'member_id' => $member->id, 'field_key' => $field, 'option_value' => $option,
            'starts_on' => $from, 'ends_on' => $to, 'note' => $note, 'source' => 'manual',
        ]);
    }

    private function member(int $number, string $first, string $last, array $attributes = []): Member
    {
        return Member::factory()->create(['member_number' => $number, 'first_name' => $first, 'middle_name' => null, 'last_name' => $last, 'joined_at' => '2000-01-01', 'left_at' => null, 'deceased_at' => null, ...$attributes]);
    }

    public function test_pages_require_member_read_access_and_appear_once_a_field_exists(): void
    {
        $this->get('/aemter')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['roles' => ['bh']]));
        foreach (['/aemter', '/aemter/verlauf', '/aemter/stichtag', '/abteilungen', '/ehrungen'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('can.viewAssignments', []));

        $this->actingAs(User::factory()->create(['roles' => ['auditor']]));
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('can.viewAssignments', []));
        $this->board();
        $this->field('custom_honor', 'honor', [['value' => 'gold', 'label' => 'Gold']], ['is_active' => false]);
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('can.viewAssignments', ['office']));
        $this->get('/aemter')->assertInertia(fn (Assert $page) => $page->component('assignments/Offices')->where('tab', 'current'));
        $this->get('/abteilungen')->assertInertia(fn (Assert $page) => $page->component('assignments/Departments')->has('fields', 0));
        $this->get('/ehrungen')->assertInertia(fn (Assert $page) => $page->component('assignments/Honors'));

        // Reading does not allow changing assignments.
        $member = $this->member(1, 'Ada', 'Lovelace');
        $this->post(route('members.assignments.store', $member->member_number), ['field' => 'custom_board', 'option' => 'chair', 'lock_version' => 0])->assertForbidden();
    }

    public function test_offices_show_holders_by_rank_vacancies_history_and_a_reference_date(): void
    {
        $this->board();
        $ada = $this->member(1, 'Ada', 'Lovelace');
        $grace = $this->member(2, 'Grace', 'Hopper', ['left_at' => '2025-12-31']);
        $alan = $this->member(3, 'Alan', 'Turing');
        $this->assign($ada, 'custom_board', 'chair', '2022-04-23', null, 'Wahl JHV');
        $this->assign($grace, 'custom_board', 'chair', '2016-01-01', '2022-04-22');
        $this->assign($grace, 'custom_board', 'auditor', '2023-01-01');
        $this->assign($alan, 'custom_board', 'auditor', '2024-01-01');
        $this->actingAs(User::factory()->create(['roles' => ['mv']]));

        $this->get('/aemter')->assertInertia(fn (Assert $page) => $page
            ->where('offices.0.options.0.label', '1. Vorsitz')
            ->where('offices.0.options.0.holders.0.name', 'Ada Lovelace')
            ->where('offices.0.options.0.holders.0.note', 'Wahl JHV')
            ->where('offices.0.options.0.vacant', false)
            ->where('offices.0.options.1.label', 'Kasse')
            ->where('offices.0.options.1.vacant', true)
            ->where('offices.0.options.2.exceeded', true)
            ->has('offices.0.options.2.holders', 2)
            ->where('offices.0.options.2.holders.1.current_member', false));

        $this->get('/aemter?board=1&members=current')->assertInertia(fn (Assert $page) => $page
            ->has('offices.0.options', 2)
            ->where('offices.0.options.0.holders.0.member_number', 1));
        $this->get('/aemter?members=current')->assertInertia(fn (Assert $page) => $page->has('offices.0.options.2.holders', 1));

        $this->get('/aemter/stichtag?date=2020-06-01')->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'date')->where('filters.date', '2020-06-01')
            ->where('offices.0.options.0.holders.0.name', 'Grace Hopper')
            ->has('offices.0.options.2.holders', 0));

        $this->get('/aemter/verlauf')->assertInertia(fn (Assert $page) => $page
            ->where('offices.0.options.0.holders.0.name', 'Ada Lovelace')
            ->where('offices.0.options.0.holders.1.name', 'Grace Hopper')
            ->where('offices.0.options.1.vacant', false));
        $this->get('/aemter/verlauf?from=2010-01-01&to=2015-12-31')->assertInertia(fn (Assert $page) => $page->has('offices.0.options.0.holders', 0));
        // The timeline view is a client-side choice and does not change the data.
        $this->get('/aemter/verlauf?view=timeline')->assertOk()->assertInertia(fn (Assert $page) => $page->where('tab', 'history')->has('offices.0.options'));
        $this->get('/aemter/verlauf?from=2020-01-01&to=2010-01-01')->assertSessionHasErrors('to');
    }

    public function test_departments_count_members_entries_and_exits_and_list_members_of_one_department(): void
    {
        $this->field('custom_sports', 'department', [['value' => 'football', 'label' => 'Fußball'], ['value' => 'gym', 'label' => 'Turnen']]);
        $ada = $this->member(1, 'Ada', 'Lovelace');
        $grace = $this->member(2, 'Grace', 'Hopper');
        $alan = $this->member(3, 'Alan', 'Turing');
        $this->assign($ada, 'custom_sports', 'football', '2020-01-01');
        $this->assign($grace, 'custom_sports', 'football', '2026-02-01');
        $this->assign($alan, 'custom_sports', 'football', '2019-01-01', '2026-03-31');
        $this->assign($alan, 'custom_sports', 'gym', '2026-04-01');
        $this->actingAs(User::factory()->create(['roles' => ['auditor']]));

        $this->get('/abteilungen')->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', '2026-01-01')->where('filters.to', '2026-06-15')
            ->where('groups.0.options.0', ['value' => 'football', 'label' => 'Fußball', 'active' => true, 'members' => 2, 'joined' => 1, 'left' => 1])
            ->where('groups.0.options.1', ['value' => 'gym', 'label' => 'Turnen', 'active' => true, 'members' => 1, 'joined' => 1, 'left' => 0])
            ->where('detail', null));

        $this->get('/abteilungen?from=2025-12-31&to=2025-12-31&field=custom_sports&option=football')->assertInertia(fn (Assert $page) => $page
            ->where('groups.0.options.0.members', 2)
            ->has('detail', 2)
            ->where('detail.0.name', 'Ada Lovelace')
            ->where('detail.1.name', 'Alan Turing'));
    }

    public function test_honors_are_listed_newest_first_with_year_and_option_filters(): void
    {
        $this->field('custom_honor', 'honor', [['value' => 'silver', 'label' => 'Silberne Nadel'], ['value' => 'gold', 'label' => 'Goldene Nadel']]);
        $ada = $this->member(1, 'Ada', 'Lovelace');
        $grace = $this->member(2, 'Grace', 'Hopper');
        $this->assign($ada, 'custom_honor', 'silver', '2015-05-01');
        $this->assign($ada, 'custom_honor', 'gold', '2025-05-01');
        $this->assign($grace, 'custom_honor', 'silver', '2025-09-01');
        $this->actingAs(User::factory()->create(['roles' => ['vereinsverwaltung']]));

        $this->get('/ehrungen')->assertInertia(fn (Assert $page) => $page
            ->where('years', [2025, 2015])
            ->where('honors.total', 3)
            ->where('honors.data.0.name', 'Grace Hopper')
            ->where('honors.data.0.label', 'Silberne Nadel')
            ->where('honors.data.2.starts_on', '2015-05-01'));
        $this->get('/ehrungen?year=2025&option=silver')->assertInertia(fn (Assert $page) => $page
            ->where('honors.total', 1)->where('honors.data.0.member_number', 2));
    }

    public function test_exports_are_protected_audited_and_contain_the_filtered_rows(): void
    {
        $this->board();
        $this->field('custom_honor', 'honor', [['value' => 'gold', 'label' => 'Goldene Nadel']]);
        $ada = $this->member(1, 'Ada', 'Lovelace');
        $this->assign($ada, 'custom_board', 'chair', '2022-04-23');
        $this->assign($ada, 'custom_honor', 'gold', '2025-05-01', null, 'JHV');
        $this->actingAs(User::factory()->create(['roles' => ['auditor']]));
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp])->get('/aemter/export?format=csv')->assertRedirect(route('password.confirm'));

        $session = ['auth.password_confirmed_at' => now()->timestamp];
        $csv = $this->withSession($session)->get('/aemter/export?format=csv&tab=current')->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();
        $this->assertStringContainsString("Feld;Amt;Vorstand;Mitgliedsnummer;Name;Zeitraum;Notiz\r\n", $csv);
        $this->assertStringContainsString("Office;\"1. Vorsitz\";Ja;1;\"Ada Lovelace\";\"seit 23.04.2022\";\r\n", $csv);
        $this->assertStringContainsString('"Unbesetzt (Pflichtamt)"', $csv);

        $honors = $this->withSession($session)->get('/ehrungen/export?format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString("\"am 01.05.2025\";\"Goldene Nadel\";Honor;1;\"Ada Lovelace\";JHV\r\n", $honors);
        $this->withSession($session)->get('/abteilungen/export?format=pdf')->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->assertSame(3, DB::table('security_audit_events')->where('event', 'data_export')->count());
    }

    public function test_board_placeholder_lists_current_board_members_by_rank(): void
    {
        $this->board();
        $ada = $this->member(1, 'Ada', 'Lovelace');
        $grace = $this->member(2, 'Grace', 'Hopper');
        $this->assign($grace, 'custom_board', 'treasurer', '2020-01-01');
        $this->assign($ada, 'custom_board', 'chair', '2022-04-23');
        $this->assign($grace, 'custom_board', 'auditor', '2020-01-01');
        $this->assign($ada, 'custom_board', 'treasurer', '2010-01-01', '2019-12-31');

        $expected = 'Vorstand: Ada Lovelace (1. Vorsitz), Grace Hopper (Kasse)';
        $templates = app(CommunicationTemplate::class);
        $this->assertContains('{{verein.vorstand}}', array_column($templates->placeholders(), 'token'));
        $this->assertSame($expected, $templates->render('Vorstand: {{verein.vorstand}}', $ada));
        FormTemplates::validate('Vertreten durch {{verein.vorstand}}');
        $this->assertSame($expected, FormTemplates::renderText('Vorstand: {{verein.vorstand}}'));
    }
}
