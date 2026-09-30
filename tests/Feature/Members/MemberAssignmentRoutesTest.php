<?php

declare(strict_types=1);

namespace Tests\Feature\Members;

use App\Models\Member;
use App\Models\MemberAssignment;
use App\Models\MemberFieldDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MemberAssignmentRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-06-15 12:00:00');
        // The default office fields would appear alongside the fields of these tests.
        MemberFieldDefinition::query()->whereIn('key', ['department_role', 'club_role'])->update(['is_active' => false]);
    }

    private function office(array $overrides = []): MemberFieldDefinition
    {
        return MemberFieldDefinition::query()->create([
            'key' => 'custom_board', 'label' => 'Vorstand', 'type' => 'office', 'section' => MemberFieldDefinition::ASSIGNMENT_SECTION,
            'position' => 1000, 'is_active' => true, 'is_custom' => true, 'required' => false, 'filterable' => false,
            'show_in_table' => false, 'selfservice_visible' => false, 'selfservice_editable' => false, 'allow_multiple' => false,
            'max_length' => 255, 'options' => [
                ['value' => 'chair', 'label' => '1. Vorsitz', 'active' => true, 'board' => true, 'mandatory' => true, 'max_holders' => 1],
                ['value' => 'deputy', 'label' => '2. Vorsitz', 'active' => true, 'board' => true, 'mandatory' => false, 'max_holders' => null],
            ],
            ...$overrides,
        ]);
    }

    public function test_member_record_lists_assignment_fields_and_assignments(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['auditor']]));
        $field = $this->office();
        $member = Member::factory()->create();
        MemberAssignment::query()->create(['member_id' => $member->id, 'field_key' => $field->key, 'option_value' => 'chair', 'starts_on' => '2024-01-01', 'note' => 'Wahl']);
        $archived = $this->office(['key' => 'custom_old', 'label' => 'Alter Beirat', 'is_active' => false]);
        MemberAssignment::query()->create(['member_id' => $member->id, 'field_key' => $archived->key, 'option_value' => 'deputy', 'starts_on' => null, 'ends_on' => '2019-12-31']);
        $this->office(['key' => 'custom_unused', 'label' => 'Unbenutzt', 'is_active' => false]);

        $this->get(route('members.show', $member->member_number))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canEdit', false)
            ->has('assignmentFields', 2)
            ->where('assignmentFields.0.key', 'custom_board')
            ->where('assignmentFields.0.type', 'office')
            ->where('assignmentFields.0.readOnly', false)
            ->where('assignmentFields.0.optionDetails.0.board', true)
            ->where('assignmentFields.0.optionDetails.0.maxHolders', 1)
            ->where('assignmentFields.1.key', 'custom_old')
            ->where('assignmentFields.1.readOnly', true)
            ->where('assignments.custom_board.0.option', 'chair')
            ->where('assignments.custom_board.0.note', 'Wahl')
            ->where('assignments.custom_old.0.starts_on', null));
    }

    public function test_editors_manage_assignments_through_the_member_record(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['mv']]));
        $field = $this->office();
        $member = Member::factory()->create(['joined_at' => '2000-01-01']);
        $show = route('members.show', ['member' => $member->member_number, 'return_to' => '/mitglieder']);

        $this->post(route('members.assignments.store', $member->member_number), [
            'field_key' => $field->key, 'option' => 'deputy', 'starts_on' => '2020-01-01', 'ends_on' => '', 'note' => '', 'lock_version' => 0, 'return_to' => '/mitglieder',
        ])->assertRedirect($show)->assertSessionHasNoErrors();
        $deputy = MemberAssignment::query()->sole();

        $this->patch(route('members.assignments.switch', [$member->member_number, $deputy->id]), ['ends_on' => '2024-03-15', 'option' => 'chair', 'note' => 'Nachwahl', 'lock_version' => 1])
            ->assertSessionHasNoErrors();
        $chair = MemberAssignment::query()->where('option_value', 'chair')->sole();
        $this->assertSame('2024-03-16', $chair->startsOn());

        $this->patch(route('members.assignments.update', [$member->member_number, $chair->id]), ['option' => 'chair', 'starts_on' => '2024-03-16', 'ends_on' => '', 'note' => 'Korrigiert', 'lock_version' => 2])
            ->assertSessionHasNoErrors();
        $this->assertSame('Korrigiert', $chair->fresh()->note);

        $this->patch(route('members.assignments.end', [$member->member_number, $chair->id]), ['ends_on' => '2026-05-31', 'lock_version' => 3])
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-05-31', $chair->fresh()->endsOn());

        $this->delete(route('members.assignments.destroy', [$member->member_number, $deputy->id]), ['lock_version' => 4])
            ->assertSessionHasNoErrors();
        $this->assertModelMissing($deputy);
        $this->assertSame(5, $member->fresh()->lock_version);
    }

    public function test_conflicts_and_warnings_reach_the_user(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['vereinsverwaltung']]));
        $field = $this->office();
        $member = Member::factory()->create(['joined_at' => '2000-01-01']);
        $other = Member::factory()->create(['joined_at' => '2000-01-01']);
        MemberAssignment::query()->create(['member_id' => $other->id, 'field_key' => $field->key, 'option_value' => 'chair', 'starts_on' => '2020-01-01']);

        $this->post(route('members.assignments.store', $member->member_number), ['field_key' => $field->key, 'option' => 'chair', 'starts_on' => '2024-01-01', 'lock_version' => 7])
            ->assertSessionHasErrors('lock_version');
        $this->post(route('members.assignments.store', $member->member_number), ['field_key' => $field->key, 'option' => 'chair', 'starts_on' => '2024-01-01', 'lock_version' => 0])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'warning');
        $this->post(route('members.assignments.store', $member->member_number), ['field_key' => $field->key, 'option' => 'deputy', 'starts_on' => '2025-01-01', 'lock_version' => 1])
            ->assertSessionHasErrors('option');
    }

    public function test_readers_cannot_change_and_assignments_stay_scoped_to_their_member(): void
    {
        $field = $this->office();
        $member = Member::factory()->create();
        $foreign = MemberAssignment::query()->create(['member_id' => Member::factory()->create()->id, 'field_key' => $field->key, 'option_value' => 'chair', 'starts_on' => '2020-01-01']);

        $this->actingAs(User::factory()->create(['roles' => ['auditor']]));
        $this->post(route('members.assignments.store', $member->member_number), ['field_key' => $field->key, 'option' => 'chair', 'lock_version' => 0])->assertForbidden();

        $this->actingAs(User::factory()->create(['roles' => ['mv']]));
        $this->delete(route('members.assignments.destroy', [$member->member_number, $foreign->id]), ['lock_version' => 0])->assertNotFound();
        $this->assertModelExists($foreign);
    }
}
