<?php

namespace Tests\Feature\Members;

use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\MemberChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BulkMemberUpdateTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $values */
    private function data(array $members, array $values, array $overrides = []): array
    {
        return [
            'members' => array_map(fn (Member $member): int => $member->member_number, $members),
            'configuration_version' => ClubSetting::current()->fields_version,
            'values' => $values,
            ...$overrides,
        ];
    }

    public function test_bulk_update_requires_edit_permission_and_exposes_capability(): void
    {
        $member = Member::factory()->create();
        $url = route('members.bulk-update');
        $this->patch($url, $this->data([$member], ['city' => 'Neu']))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create(['roles' => ['auditor']]));
        $this->get(route('members.index'))->assertInertia(fn (Assert $page) => $page->where('canBulkEdit', false));
        $this->patch($url, $this->data([$member], ['city' => 'Neu']))->assertForbidden();

        $this->actingAs(User::factory()->create(['roles' => ['mv']]));
        $this->get(route('members.index'))->assertInertia(fn (Assert $page) => $page->where('canBulkEdit', true)->has('configurationVersion'));
    }

    public function test_selected_members_receive_multiple_fields_and_individual_history_entries(): void
    {
        $actor = User::factory()->create(['roles' => ['mv'], 'name' => 'Massenbearbeitung']);
        $this->actingAs($actor);
        $members = Member::factory()->count(2)->create(['city' => 'Altstadt', 'is_honorary' => false]);
        $untouched = Member::factory()->create(['city' => 'Unverändert', 'is_honorary' => false]);

        $this->patch(route('members.bulk-update'), $this->data($members->all(), ['city' => 'Neustadt', 'is_honorary' => true]))
            ->assertSessionHasNoErrors()->assertRedirect();

        foreach ($members as $member) {
            $current = $member->fresh();
            $this->assertSame('Neustadt', $current->city);
            $this->assertTrue($current->is_honorary);
            $this->assertSame(1, $current->lock_version);
        }
        $this->assertSame('Unverändert', $untouched->fresh()->city);
        $this->assertFalse($untouched->fresh()->is_honorary);
        $this->assertDatabaseCount('member_changes', 2);
        foreach (MemberChange::all() as $change) {
            $this->assertSame($actor->id, $change->actor_id);
            $this->assertSame(['city', 'is_honorary'], $change->changed_fields);
            $this->assertSame('Massenbearbeitung', $change->actor_name);
        }
    }

    public function test_no_op_members_are_not_versioned_or_added_to_history(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['mv']]));
        $unchanged = Member::factory()->create(['city' => 'Zielort']);
        $changed = Member::factory()->create(['city' => 'Anderer Ort']);

        $this->patch(route('members.bulk-update'), $this->data([$unchanged, $changed], ['city' => 'Zielort']))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $unchanged->fresh()->lock_version);
        $this->assertSame(1, $changed->fresh()->lock_version);
        $this->assertDatabaseCount('member_changes', 1);
    }

    public function test_failure_for_one_member_rolls_back_the_whole_bulk_update(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['mv']]));
        $valid = Member::factory()->create(['birth_date' => '1990-01-01', 'joined_at' => '2010-01-01', 'left_at' => null]);
        $invalid = Member::factory()->create(['birth_date' => '1990-01-01', 'joined_at' => '2020-01-01', 'left_at' => null]);

        $this->patchJson(route('members.bulk-update'), $this->data([$valid, $invalid], ['left_at' => '2015-01-01']))
            ->assertUnprocessable()->assertJsonValidationErrors('values.left_at');

        $this->assertNull($valid->fresh()->left_at);
        $this->assertNull($invalid->fresh()->left_at);
        $this->assertSame(0, $valid->fresh()->lock_version);
        $this->assertDatabaseCount('member_changes', 0);
    }

    public function test_bulk_update_rejects_unknown_fields_stale_configuration_and_missing_members(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['mv']]));
        $member = Member::factory()->create(['city' => 'Alt']);

        $this->patchJson(route('members.bulk-update'), $this->data([$member], ['password' => 'manipuliert']))
            ->assertUnprocessable()->assertJsonValidationErrors('form');
        $this->patchJson(route('members.bulk-update'), $this->data([$member], ['city' => 'Neu'], ['configuration_version' => 99]))
            ->assertUnprocessable()->assertJsonValidationErrors('form');
        $this->patchJson(route('members.bulk-update'), [
            'members' => [$member->member_number, 999999999],
            'configuration_version' => ClubSetting::current()->fields_version,
            'values' => ['city' => 'Neu'],
        ])->assertUnprocessable()->assertJsonValidationErrors('members');

        $this->assertSame('Alt', $member->fresh()->city);
        $this->assertDatabaseCount('member_changes', 0);
    }
}
