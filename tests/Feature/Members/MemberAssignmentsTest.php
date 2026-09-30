<?php

declare(strict_types=1);

namespace Tests\Feature\Members;

use App\Members\MemberAssignments;
use App\Members\MemberFields;
use App\Models\Member;
use App\Models\MemberAssignment;
use App\Models\MemberChange;
use App\Models\MemberFieldDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MemberAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    private MemberAssignments $assignments;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-06-15 12:00:00');
        $this->assignments = app(MemberAssignments::class);
        $this->actor = User::factory()->create(['roles' => ['mv']]);
    }

    /** @param list<array<string, mixed>> $options */
    private function field(string $type, array $options, array $overrides = []): MemberFieldDefinition
    {
        return MemberFieldDefinition::query()->create([
            'key' => 'custom_'.$type.'_'.MemberFieldDefinition::query()->count(), 'label' => ucfirst($type), 'type' => $type,
            'section' => MemberFieldDefinition::ASSIGNMENT_SECTION, 'position' => 1000, 'is_active' => true, 'is_custom' => true,
            'required' => false, 'filterable' => false, 'show_in_table' => false, 'selfservice_visible' => false,
            'selfservice_editable' => false, 'allow_multiple' => false, 'max_length' => 255,
            'options' => array_map(fn (array $option): array => ['active' => true, ...$option], $options),
            ...$overrides,
        ]);
    }

    private function office(array $overrides = []): MemberFieldDefinition
    {
        return $this->field('office', [
            ['value' => 'chair', 'label' => '1. Vorsitz', 'board' => true, 'mandatory' => true, 'max_holders' => 1],
            ['value' => 'deputy', 'label' => '2. Vorsitz', 'board' => true, 'mandatory' => false, 'max_holders' => null],
            ['value' => 'old', 'label' => 'Altes Amt', 'active' => false],
        ], $overrides);
    }

    private function add(Member $member, MemberFieldDefinition $field, string $option, ?string $from, ?string $to = null): array
    {
        return $this->assignments->add($member, $this->actor, $member->fresh()->lock_version, $field->key, ['option' => $option, 'starts_on' => $from, 'ends_on' => $to]);
    }

    private function assertRejected(string $key, callable $action): void
    {
        try {
            $action();
            $this->fail('Die Aktion hätte abgelehnt werden müssen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }

    public function test_sql_scope_and_php_check_agree_on_all_boundaries(): void
    {
        $field = $this->office(['allow_multiple' => true]);
        $member = Member::factory()->create(['joined_at' => '2000-01-01']);
        $this->add($member, $field, 'chair', '2020-01-01', '2020-12-31');
        $this->add($member, $field, 'deputy', null, '2019-06-30');
        $this->add($member, $field, 'deputy', '2027-01-01');
        $cases = ['2019-06-30', '2019-07-01', '2019-12-31', '2020-01-01', '2020-12-31', '2021-01-01', '2026-06-15', '2027-01-01', '1950-01-01'];
        foreach ($cases as $day) {
            $sql = MemberAssignment::query()->activeOn($day)->orderBy('id')->pluck('id')->all();
            $php = MemberAssignment::query()->orderBy('id')->get()->filter(fn (MemberAssignment $assignment): bool => $assignment->isActiveOn($day))->pluck('id')->values()->all();
            $this->assertSame($php, $sql, "Stichtag {$day}");
        }
        $this->assertSame(['chair'], MemberAssignment::query()->activeOn('2020-12-31')->pluck('option_value')->all());
        $this->assertSame([], MemberAssignment::query()->activeOn('2021-01-01')->pluck('option_value')->all());
        $this->assertSame(['deputy'], MemberAssignment::query()->activeOn('1950-01-01')->pluck('option_value')->all());
        $this->assertSame(3, MemberAssignment::query()->overlapping('2019-01-01', '2030-01-01')->count());
        $this->assertSame(1, MemberAssignment::query()->overlapping('2019-07-01', '2026-12-31')->count());
    }

    public function test_adding_records_history_and_protects_against_stale_versions(): void
    {
        $field = $this->office();
        $member = Member::factory()->create(['joined_at' => '2010-01-01']);

        $warnings = $this->assignments->add($member, $this->actor, 0, $field->key, ['option' => 'chair', 'starts_on' => '2024-01-01', 'ends_on' => '', 'note' => 'Wahl JHV']);

        $this->assertSame([], $warnings);
        $this->assertSame(1, $member->fresh()->lock_version);
        $this->assertDatabaseHas('member_assignments', ['member_id' => $member->id, 'field_key' => $field->key, 'option_value' => 'chair', 'ends_on' => null, 'note' => 'Wahl JHV', 'source' => 'manual', 'created_by' => $this->actor->id]);
        $change = MemberChange::query()->where('member_id', $member->id)->sole();
        $this->assertSame([$field->key], $change->changed_fields);
        $this->assertSame([$field->key => []], $change->before);
        $this->assertSame([$field->key => [['option' => 'chair', 'starts_on' => '2024-01-01', 'ends_on' => null, 'note' => 'Wahl JHV']]], $change->after);
        $this->assertSame('1. Vorsitz', $change->field_schema[$field->key]['options']['chair']);

        $this->assertRejected('lock_version', fn () => $this->assignments->add($member, $this->actor, 0, $field->key, ['option' => 'deputy', 'starts_on' => '2010-01-01', 'ends_on' => '2010-12-31']));
        $this->assertSame(1, MemberAssignment::query()->count());
    }

    public function test_only_member_editors_may_change_assignments(): void
    {
        $field = $this->office();
        $member = Member::factory()->create();

        $this->expectException(HttpException::class);
        $this->assignments->add($member, User::factory()->create(['roles' => ['auditor']]), 0, $field->key, ['option' => 'chair', 'starts_on' => '2024-01-01']);
    }

    public function test_unknown_inactive_and_regular_fields_and_options_are_rejected(): void
    {
        $office = $this->office();
        $member = Member::factory()->create();

        $this->assertRejected('option', fn () => $this->add($member, $office, 'old', '2024-01-01'));
        $this->assertRejected('option', fn () => $this->add($member, $office, 'unknown', '2024-01-01'));
        $this->assertRejected('ends_on', fn () => $this->add($member, $office, 'chair', '2024-01-01', '2023-12-31'));
        $this->assertRejected('form', fn () => $this->assignments->add($member, $this->actor, 0, 'membership_type', ['option' => 'Aktiv']));
        $office->update(['is_active' => false]);
        $this->assertRejected('form', fn () => $this->add($member, $office, 'chair', '2024-01-01'));
        $this->assertSame(0, MemberAssignment::query()->count());
    }

    public function test_the_same_option_may_not_overlap_or_directly_follow_itself(): void
    {
        $department = $this->field('department', [['value' => 'football', 'label' => 'Fußball'], ['value' => 'gym', 'label' => 'Turnen']]);
        $member = Member::factory()->create(['joined_at' => '2000-01-01']);
        $this->add($member, $department, 'football', '2020-01-01', '2020-12-31');

        $this->assertRejected('option', fn () => $this->add($member, $department, 'football', '2020-06-01'));
        $this->assertRejected('option', fn () => $this->add($member, $department, 'football', '2021-01-01'));
        $this->assertRejected('option', fn () => $this->add($member, $department, 'football', null, '2020-01-01'));
        $this->add($member, $department, 'football', '2022-01-01');
        $this->add($member, $department, 'gym', '2020-03-01');

        $this->assertSame(2, MemberAssignment::query()->activeOn('2026-06-15')->count());
    }

    public function test_offices_of_one_field_overlap_only_when_the_field_allows_it(): void
    {
        $single = $this->office();
        $member = Member::factory()->create(['joined_at' => '2000-01-01']);
        $this->add($member, $single, 'chair', '2020-01-01', '2021-12-31');

        $this->assertRejected('option', fn () => $this->add($member, $single, 'deputy', '2021-12-31'));
        $this->add($member, $single, 'deputy', '2022-01-01');

        $multiple = $this->office(['allow_multiple' => true]);
        $this->add($member, $multiple, 'chair', '2020-01-01');
        $this->add($member, $multiple, 'deputy', '2020-01-01');
        $this->assertSame(2, MemberAssignment::query()->where('field_key', $multiple->key)->activeOn('2026-06-15')->count());
    }

    public function test_switching_ends_the_old_and_starts_the_new_office_the_next_day(): void
    {
        $field = $this->office();
        $member = Member::factory()->create(['joined_at' => '2000-01-01']);
        $this->add($member, $field, 'deputy', '2020-01-01');
        $deputy = MemberAssignment::query()->sole();

        $this->assignments->switch($member, $this->actor, 1, $deputy, '2024-03-15', 'chair', 'Nachwahl');

        $this->assertSame('2024-03-15', $deputy->fresh()->endsOn());
        $chair = MemberAssignment::query()->where('option_value', 'chair')->sole();
        $this->assertSame('2024-03-16', $chair->startsOn());
        $this->assertNull($chair->ends_on);
        $this->assertSame('Nachwahl', $chair->note);
        $this->assertSame(2, MemberChange::query()->where('member_id', $member->id)->count());
        $this->assertSame(2, $member->fresh()->lock_version);

        $this->assertRejected('ends_on', fn () => $this->assignments->switch($member, $this->actor, 2, $deputy, '2025-01-01', 'chair'));
        $this->assertRejected('option', fn () => $this->assignments->switch($member, $this->actor, 2, $chair, '2025-01-01', 'chair'));
        $this->assertRejected('ends_on', fn () => $this->assignments->switch($member, $this->actor, 2, $chair, '2024-01-01', 'deputy'));
    }

    public function test_ending_correcting_and_deleting_assignments(): void
    {
        $field = $this->office();
        $member = Member::factory()->create(['joined_at' => '2000-01-01']);
        $this->add($member, $field, 'deputy', '2020-01-01');
        $assignment = MemberAssignment::query()->sole();

        $this->assertRejected('ends_on', fn () => $this->assignments->end($member, $this->actor, 1, $assignment, '2019-12-31'));
        $this->assignments->end($member, $this->actor, 1, $assignment, '2023-12-31');
        $this->assertSame('2023-12-31', $assignment->fresh()->endsOn());
        $this->assertRejected('ends_on', fn () => $this->assignments->end($member, $this->actor, 2, $assignment, '2024-12-31'));

        // A later deactivated option stays valid for corrections of existing assignments.
        $field->update(['options' => array_map(fn (array $option): array => [...$option, 'active' => false], $field->options)]);
        $this->assignments->correct($member, $this->actor, 2, $assignment, ['option' => 'deputy', 'starts_on' => '2019-01-01', 'ends_on' => '2023-12-31', 'note' => 'Beginn laut Protokoll']);
        $this->assertSame('2019-01-01', $assignment->fresh()->startsOn());
        $this->assertRejected('option', fn () => $this->assignments->correct($member, $this->actor, 3, $assignment, ['option' => 'chair', 'starts_on' => '2019-01-01']));

        $this->assignments->delete($member, $this->actor, 3, $assignment);
        $this->assertSame(0, MemberAssignment::query()->count());
        $last = MemberChange::query()->where('member_id', $member->id)->latest('version')->first();
        $this->assertSame([], $last->after[$field->key]);
        $this->assertSame('deputy', $last->before[$field->key][0]['option']);
        $this->assertSame(4, $member->fresh()->lock_version);
    }

    public function test_unchanged_corrections_write_no_history(): void
    {
        $field = $this->office();
        $member = Member::factory()->create(['joined_at' => '2000-01-01']);
        $this->add($member, $field, 'deputy', '2020-01-01');

        $this->assignments->correct($member, $this->actor, 1, MemberAssignment::query()->sole(), ['option' => 'deputy', 'starts_on' => '2020-01-01']);

        $this->assertSame(1, $member->fresh()->lock_version);
        $this->assertSame(1, MemberChange::query()->count());
    }

    public function test_honors_are_dated_events_that_repeat_only_when_allowed(): void
    {
        $honors = $this->field('honor', [
            ['value' => 'silver', 'label' => '25 Jahre', 'repeatable' => false],
            ['value' => 'athlete', 'label' => 'Sportler des Jahres', 'repeatable' => true],
        ]);
        $member = Member::factory()->create(['joined_at' => '2000-01-01']);
        $this->add($member, $honors, 'silver', '2025-03-01');
        $this->add($member, $honors, 'athlete', '2024-12-01');
        $this->add($member, $honors, 'athlete', '2025-12-01');

        $this->assertRejected('option', fn () => $this->add($member, $honors, 'silver', '2026-03-01'));
        $this->assertRejected('option', fn () => $this->add($member, $honors, 'athlete', '2025-12-01'));
        $this->assertRejected('ends_on', fn () => $this->add($member, $honors, 'athlete', '2020-01-01', '2020-01-02'));
        $this->assertRejected('starts_on', fn () => $this->add($member, $honors, 'athlete', '2026-06-16'));
        $honor = MemberAssignment::query()->where('option_value', 'silver')->sole();
        $this->assertRejected('ends_on', fn () => $this->assignments->end($member, $this->actor, 3, $honor, '2026-01-01'));
        $this->assertRejected('option', fn () => $this->assignments->switch($member, $this->actor, 3, $honor, '2026-01-01', 'athlete'));
        $this->assertSame(3, MemberAssignment::query()->count());
    }

    public function test_exceeding_the_holder_limit_warns_only_for_simultaneous_holders(): void
    {
        $field = $this->office();
        [$first, $second, $third] = Member::factory()->count(3)->create(['joined_at' => '2000-01-01']);
        $this->assertSame([], $this->add($first, $field, 'chair', '2018-01-01', '2019-12-31'));
        $this->assertSame([], $this->add($second, $field, 'chair', '2020-01-01'));
        $this->assertSame([], $this->add($third, $field, 'deputy', '2018-01-01'));

        // Overlaps three terms, but never more than two holders at once.
        $pair = $this->field('office', [['value' => 'auditor', 'label' => 'Kassenprüfung', 'max_holders' => 2]]);
        $this->add($first, $pair, 'auditor', '2010-01-01', '2011-12-31');
        $this->add($second, $pair, 'auditor', '2013-01-01', '2014-12-31');
        $this->assertSame([], $this->add($third, $pair, 'auditor', '2010-01-01', '2014-12-31'));

        $warnings = $this->assignments->switch($third, $this->actor, $third->fresh()->lock_version, MemberAssignment::query()->where('member_id', $third->id)->where('option_value', 'deputy')->sole(), '2019-12-31', 'chair');
        $this->assertSame(['„1. Vorsitz“ ist in diesem Zeitraum zeitweise mit 2 Personen besetzt, vorgesehen sind höchstens 1.'], $warnings);
        $this->assertSame(2, MemberAssignment::query()->where('field_key', $field->key)->where('option_value', 'chair')->activeOn('2026-06-15')->count());
    }

    public function test_assignments_outside_the_membership_are_saved_with_a_warning(): void
    {
        $field = $this->office(['allow_multiple' => true]);
        $member = Member::factory()->create(['joined_at' => '2015-01-01', 'left_at' => '2024-12-31']);

        $this->assertSame([], $this->add($member, $field, 'deputy', '2016-01-01', '2024-12-31'));
        $expected = ['„1. Vorsitz“ liegt ganz oder teilweise außerhalb der Mitgliedschaft.'];
        $this->assertSame($expected, $this->add($member, $field, 'chair', '2014-01-01', '2016-12-31'));
        $contact = Member::factory()->create(['joined_at' => null]);
        $this->assertSame($expected, $this->add($contact, $field, 'chair', '2020-01-01'));
        $this->assertSame(3, MemberAssignment::query()->count());
    }

    public function test_temporal_fields_stay_out_of_the_member_form(): void
    {
        $field = $this->office();

        $this->assertNotContains($field->key, MemberFields::writable());
        $this->assertNotContains($field->key, array_column(MemberFields::directoryFields(), 'key'));
    }
}
