<?php

declare(strict_types=1);

namespace Tests\Feature\Members;

use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\MemberAssignment;
use App\Models\MemberChange;
use App\Models\MemberFieldDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BulkAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-06-15 12:00:00');
    }

    /** @param list<array<string, mixed>> $options */
    private function field(string $key, string $type, array $options): MemberFieldDefinition
    {
        return MemberFieldDefinition::query()->create([
            'key' => $key, 'label' => ucfirst($type), 'type' => $type,
            'section' => MemberFieldDefinition::ASSIGNMENT_SECTION, 'position' => 1000, 'is_active' => true, 'is_custom' => true,
            'required' => false, 'filterable' => true, 'show_in_table' => false, 'selfservice_visible' => false,
            'selfservice_editable' => false, 'allow_multiple' => false, 'max_length' => 255,
            'options' => array_map(fn (array $option): array => ['active' => true, ...$option], $options),
        ]);
    }

    private function honors(): MemberFieldDefinition
    {
        return $this->field('custom_honor', 'honor', [
            ['value' => 'silver', 'label' => 'Ehrennadel Silber', 'jubilee_years' => 25],
            ['value' => 'gold', 'label' => 'Ehrennadel Gold', 'jubilee_years' => 40],
            ['value' => 'thanks', 'label' => 'Dankurkunde', 'repeatable' => true],
        ]);
    }

    private function member(int $number, string $joined, array $attributes = []): Member
    {
        return Member::factory()->create(['member_number' => $number, 'first_name' => 'M'.$number, 'middle_name' => null, 'last_name' => 'Test', 'joined_at' => $joined, 'left_at' => null, 'deceased_at' => null, ...$attributes]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return ['configuration_version' => ClubSetting::current()->fields_version, 'action' => 'add', ...$overrides];
    }

    public function test_an_honor_is_added_for_all_selected_members_with_one_history_entry_each(): void
    {
        $this->honors();
        $first = $this->member(1, '2000-01-01');
        $second = $this->member(2, '2000-01-01');
        $this->actingAs(User::factory()->create(['roles' => ['mv']]));

        $this->post(route('members.bulk-assign'), $this->payload([
            'members' => [1, 2], 'field' => 'custom_honor', 'option' => 'silver', 'starts_on' => '2026-06-01', 'note' => '25 Jahre Mitgliedschaft',
        ]))->assertRedirect()->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', '2 Mitglieder wurden aktualisiert.');

        foreach ([$first, $second] as $member) {
            $this->assertDatabaseHas('member_assignments', ['member_id' => $member->id, 'field_key' => 'custom_honor', 'option_value' => 'silver', 'starts_on' => '2026-06-01', 'note' => '25 Jahre Mitgliedschaft']);
            $this->assertSame(1, $member->fresh()->lock_version);
            $this->assertSame(['custom_honor'], MemberChange::query()->where('member_id', $member->id)->sole()->changed_fields);
        }

        // Identical warnings of several members are combined.
        $this->post(route('members.bulk-assign'), $this->payload(['members' => [1, 2], 'field' => 'custom_honor', 'option' => 'thanks', 'starts_on' => '1999-05-01']))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'warning')
            ->assertInertiaFlash('toast.message', '2 Mitglieder wurden aktualisiert. Hinweis: „Dankurkunde“ liegt ganz oder teilweise außerhalb der Mitgliedschaft (2 Mitglieder).');
    }

    public function test_one_conflict_rejects_the_whole_selection(): void
    {
        $this->honors();
        $this->member(1, '2000-01-01');
        $honored = $this->member(2, '2000-01-01');
        MemberAssignment::query()->create(['member_id' => $honored->id, 'field_key' => 'custom_honor', 'option_value' => 'silver', 'starts_on' => '2025-01-01']);
        $this->actingAs(User::factory()->create(['roles' => ['vereinsverwaltung']]));

        $this->post(route('members.bulk-assign'), $this->payload(['members' => [1, 2], 'field' => 'custom_honor', 'option' => 'silver', 'starts_on' => '2026-06-01']))
            ->assertSessionHasErrors(['members' => 'M2 Test (Nr. 2): „Ehrennadel Silber“ ist für dieses Mitglied bereits erfasst.']);
        $this->assertSame(1, MemberAssignment::query()->count());
        $this->assertSame(0, MemberChange::query()->count());

        $this->post(route('members.bulk-assign'), $this->payload(['members' => [1], 'field' => 'custom_honor', 'option' => 'silver', 'starts_on' => '2027-01-01']))
            ->assertSessionHasErrors('members');
        $this->post(route('members.bulk-assign'), $this->payload(['members' => [1], 'field' => 'custom_honor', 'option' => '']))->assertSessionHasErrors('option');
        $this->post(route('members.bulk-assign'), $this->payload(['members' => [1], 'field' => 'club_role', 'option' => 'Kassierer', 'configuration_version' => 99]))->assertSessionHasErrors('form');
        $this->post(route('members.bulk-assign'), $this->payload(['members' => [1, 99], 'field' => 'custom_honor', 'option' => 'gold']))->assertSessionHasErrors('members');
        $this->post(route('members.bulk-assign'), $this->payload(['members' => [1], 'field' => 'first_name', 'option' => 'x']))->assertSessionHasErrors('field');
    }

    public function test_offices_are_ended_for_members_holding_them_and_warnings_are_summarized(): void
    {
        $chair = $this->member(1, '2000-01-01');
        $auditor = $this->member(2, '2000-01-01');
        $this->member(3, '2000-01-01');
        MemberAssignment::query()->create(['member_id' => $chair->id, 'field_key' => 'club_role', 'option_value' => '1. Vorsitzender', 'starts_on' => '2020-01-01']);
        MemberAssignment::query()->create(['member_id' => $auditor->id, 'field_key' => 'club_role', 'option_value' => 'Kassenprüfer', 'starts_on' => '2020-01-01']);
        // Starts after the end date and therefore stays.
        MemberAssignment::query()->create(['member_id' => $auditor->id, 'field_key' => 'department_role', 'option_value' => 'Delegierte', 'starts_on' => '2026-07-01']);
        $this->actingAs(User::factory()->create(['roles' => ['admin']]));

        $this->post(route('members.bulk-assign'), $this->payload(['action' => 'end', 'members' => [1, 2, 3], 'field' => 'club_role', 'ends_on' => '2026-06-30']))
            ->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', '2 Mitglieder wurden aktualisiert. Bei 1 Mitglied gab es keine passende laufende Zuordnung.');
        $this->assertSame(['2026-06-30'], MemberAssignment::query()->where('field_key', 'club_role')->get()->map->endsOn()->unique()->values()->all());

        $this->post(route('members.bulk-assign'), $this->payload(['action' => 'end', 'members' => [2], 'field' => 'department_role', 'ends_on' => '2026-06-30']))->assertSessionHasNoErrors();
        $this->assertNull(MemberAssignment::query()->where('field_key', 'department_role')->sole()->ends_on);
        $this->honors();
        $this->post(route('members.bulk-assign'), $this->payload(['action' => 'end', 'members' => [1], 'field' => 'custom_honor', 'ends_on' => '2026-06-30']))->assertSessionHasErrors('field');

        MemberFieldDefinition::query()->where('key', 'club_role')->update(['options' => [['value' => 'Kasse', 'label' => 'Kasse', 'active' => true, 'board' => true, 'mandatory' => false, 'max_holders' => 1]]]);
        $this->post(route('members.bulk-assign'), $this->payload(['members' => [1, 2, 3], 'field' => 'club_role', 'option' => 'Kasse', 'starts_on' => '2026-07-01']))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'warning')
            ->assertInertiaFlash('toast.message', '3 Mitglieder wurden aktualisiert. Hinweis: „Kasse“ ist in diesem Zeitraum zeitweise mit 2 Personen besetzt, vorgesehen sind höchstens 1. „Kasse“ ist in diesem Zeitraum zeitweise mit 3 Personen besetzt, vorgesehen sind höchstens 1.');
    }

    public function test_only_members_with_write_access_may_assign_in_bulk(): void
    {
        $this->honors();
        $this->member(1, '2000-01-01');
        $this->actingAs(User::factory()->create(['roles' => ['auditor']]));
        $this->post(route('members.bulk-assign'), $this->payload(['members' => [1], 'field' => 'custom_honor', 'option' => 'silver']))->assertForbidden();
        $this->get(route('members.index'))->assertInertia(fn (Assert $page) => $page->where('canManageAssignments', false));
        $this->actingAs(User::factory()->create(['roles' => ['mv']]));
        $this->get(route('members.index'))->assertInertia(fn (Assert $page) => $page->where('canManageAssignments', true));
        $this->assertSame(0, MemberAssignment::query()->count());
    }

    public function test_due_jubilees_list_current_members_without_the_honor(): void
    {
        $this->honors();
        $due = $this->member(1, '2000-01-01');
        $this->member(2, '2001-12-31');
        $this->member(3, '2010-01-01');
        $this->member(4, '1990-01-01', ['left_at' => '2025-12-31']);
        $honored = $this->member(5, '1995-01-01');
        MemberAssignment::query()->create(['member_id' => $honored->id, 'field_key' => 'custom_honor', 'option_value' => 'silver', 'starts_on' => '2020-01-01']);
        $this->member(6, '1986-03-01');
        $this->actingAs(User::factory()->create(['roles' => ['auditor']]));

        $this->get('/ehrungen/jubilaeen')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('assignments/Honors')
            ->where('tab', 'jubilees')
            ->where('filters.date', '2026-12-31')
            ->where('honors', null)
            ->where('canAssign', false)
            ->has('jubilees', 2)
            ->where('jubilees.0.label', 'Ehrennadel Silber')
            ->where('jubilees.0.years', 25)
            ->where('jubilees.0.members', fn ($members): bool => collect($members)->pluck('member_number')->all() === [6, 1, 2])
            ->where('jubilees.0.members.1.jubilee_on', '2025-01-01')
            ->where('jubilees.0.members.1.due', true)
            ->where('jubilees.0.members.2.jubilee_on', '2026-12-31')
            ->where('jubilees.0.members.2.due', false)
            ->where('jubilees.1.label', 'Ehrennadel Gold')
            ->where('jubilees.1.members', fn ($members): bool => collect($members)->pluck('member_number')->all() === [6]));
        $this->get('/ehrungen/jubilaeen?date=2026-06-15&option=silver&field=custom_honor')->assertInertia(fn (Assert $page) => $page
            ->has('jubilees', 1)->where('jubilees.0.members', fn ($members): bool => collect($members)->pluck('member_number')->all() === [6, 1]));

        $csv = $this->withSession(['auth.password_confirmed_at' => now()->timestamp])->get('/ehrungen/export?format=csv&tab=jubilees')->assertOk()->streamedContent();
        $this->assertStringContainsString('"Ehrennadel Silber";Honor;1;"M1 Test";01.01.2000;01.01.2025;Fällig', $csv);
        $this->assertStringContainsString('"Ehrennadel Silber";Honor;2;"M2 Test";31.12.2001;31.12.2026;Bevorstehend', $csv);
        $this->assertSame(0, substr_count($csv, 'M4 Test'));

        // The list tab keeps working next to it.
        $this->get('/ehrungen')->assertInertia(fn (Assert $page) => $page->where('tab', 'list')->where('honors.total', 1)->has('jubilees', 0));
        $this->assertTrue($due->isCurrentMember());
    }
}
