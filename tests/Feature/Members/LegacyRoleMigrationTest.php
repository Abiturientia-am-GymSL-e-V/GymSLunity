<?php

declare(strict_types=1);

namespace Tests\Feature\Members;

use App\Calendar\CalendarAccess;
use App\Communication\CommunicationTemplate;
use App\Models\ClubCalendar;
use App\Models\Member;
use App\Models\MemberAssignment;
use App\Models\MemberChange;
use App\Models\MemberFieldDefinition;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class LegacyRoleMigrationTest extends TestCase
{
    use RefreshDatabase;

    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-06-15 12:00:00');
        $this->migration = require database_path('migrations/2026_09_30_040000_migrate_legacy_roles_to_assignments.php');
        // Back to the state before the update: select fields with values in members.
        $this->migration->down();
    }

    public function test_values_become_assignments_with_history_and_keep_their_keys(): void
    {
        $this->assertSame('select', $this->field('club_role')->type);
        $treasurer = Member::factory()->create(['club_role' => 'Kassierer', 'department_role' => 'Delegierte', 'lock_version' => 3]);
        $former = Member::factory()->create(['club_role' => 'Schriftführer', 'joined_at' => '2010-01-01', 'left_at' => '2025-12-31', 'deceased_at' => '2026-02-01']);
        $unknown = Member::factory()->create(['club_role' => 'Ehrenvorsitz']);
        $none = Member::factory()->create(['club_role' => '', 'department_role' => null]);
        $version = DB::table('club_settings')->where('id', 1)->value('fields_version');

        $this->migration->up();

        $club = $this->field('club_role');
        $this->assertSame('office', $club->type);
        $this->assertSame(MemberFieldDefinition::ASSIGNMENT_SECTION, $club->section);
        $this->assertTrue($club->is_custom);
        $this->assertTrue($club->filterable);
        $this->assertFalse($club->allow_multiple);
        $this->assertSame(['value' => 'Kassierer', 'label' => 'Kassierer', 'active' => true, 'board' => false, 'mandatory' => false, 'max_holders' => null], collect($club->options)->firstWhere('value', 'Kassierer'));
        // Stored values without an option keep their label as an inactive option.
        $this->assertFalse(collect($club->options)->firstWhere('value', 'Ehrenvorsitz')['active']);
        $this->assertSame('office', $this->field('department_role')->type);
        $this->assertSame($version + 1, DB::table('club_settings')->where('id', 1)->value('fields_version'));

        $this->assertSame(4, MemberAssignment::query()->count());
        $this->assertDatabaseHas('member_assignments', ['member_id' => $treasurer->id, 'field_key' => 'club_role', 'option_value' => 'Kassierer', 'starts_on' => null, 'ends_on' => null, 'source' => 'migration', 'note' => 'Aus Altdaten übernommen']);
        $this->assertDatabaseHas('member_assignments', ['member_id' => $treasurer->id, 'field_key' => 'department_role', 'option_value' => 'Delegierte']);
        // Former members: the office ends on the earlier of leaving and death.
        $this->assertSame('2025-12-31', MemberAssignment::query()->where('member_id', $former->id)->sole()->endsOn());
        $this->assertSame(0, MemberAssignment::query()->where('member_id', $none->id)->count());

        $change = MemberChange::query()->where('member_id', $treasurer->id)->sole();
        $this->assertNull($change->actor_id);
        $this->assertSame('System (Migration)', $change->actor_name);
        $this->assertSame(4, $change->version);
        $this->assertSame(4, $treasurer->fresh()->lock_version);
        $this->assertEqualsCanonicalizing(['club_role', 'department_role'], $change->changed_fields);
        $this->assertSame(['club_role' => 'Kassierer', 'department_role' => 'Delegierte'], $change->before);
        $this->assertSame([['option' => 'Kassierer', 'starts_on' => null, 'ends_on' => null, 'note' => 'Aus Altdaten übernommen']], $change->after['club_role']);
        $this->assertSame('office', $change->field_schema['club_role']['type']);
        $this->assertSame(0, MemberChange::query()->where('member_id', $none->id)->count());
        $this->assertSame('Ehrenvorsitz', MemberAssignment::query()->where('member_id', $unknown->id)->sole()->option_value);

        // Running it again changes nothing.
        $this->migration->up();
        $this->assertSame(4, MemberAssignment::query()->count());
        $this->assertSame(3, MemberChange::query()->count());
        $this->assertSame($version + 1, DB::table('club_settings')->where('id', 1)->value('fields_version'));
    }

    public function test_placeholders_filters_and_calendar_rules_use_current_offices(): void
    {
        $treasurer = Member::factory()->create(['first_name' => 'Tilda', 'club_role' => 'Kassierer', 'department_role' => 'Delegierte']);
        $former = Member::factory()->create(['club_role' => 'Kassierer', 'joined_at' => '2010-01-01', 'left_at' => '2025-12-31']);
        $calendar = ClubCalendar::query()->where('type', 'general')->sole();
        $calendar->rules()->create(['field_key' => 'club_role', 'value' => 'Kassierer']);
        $this->migration->up();

        $template = app(CommunicationTemplate::class);
        $this->assertSame('Tilda: Kassierer / Delegierte', $template->render('{{mitglied.first_name}}: {{mitglied.club_role}} / {{mitglied.department_role}}', $treasurer->fresh()));
        $this->assertSame('', $template->render('{{mitglied.club_role}}', $former->fresh()));

        $this->assertTrue(app(CalendarAccess::class)->forMember($treasurer->fresh())->contains('id', $calendar->id));
        $this->assertFalse(app(CalendarAccess::class)->forMember($former->fresh())->contains('id', $calendar->id));

        $this->actingAs(User::factory()->create(['roles' => ['mv']]));
        $this->get(route('members.index', ['club_role' => 'Kassierer']))->assertInertia(fn (Assert $page) => $page
            ->where('members.total', 1)
            ->where('members.data.0.assignments.club_role', ['Kassierer']));
        $this->get(route('members.index', ['custom' => ['club_role' => '__ever__:Kassierer']]))->assertInertia(fn (Assert $page) => $page->where('members.total', 2));
    }

    public function test_rollback_restores_select_fields_until_assignments_were_recorded(): void
    {
        $member = Member::factory()->create(['club_role' => 'Kassierer']);
        $this->migration->up();
        $this->migration->down();

        $this->assertSame('select', $this->field('club_role')->type);
        $this->assertSame('roles', $this->field('club_role')->section);
        $this->assertFalse($this->field('club_role')->is_custom);
        $this->assertSame(['value' => 'Kassierer', 'label' => 'Kassierer', 'active' => true], collect($this->field('club_role')->options)->firstWhere('value', 'Kassierer'));
        $this->assertSame(0, MemberAssignment::query()->count());
        $this->assertSame('Kassierer', DB::table('members')->where('id', $member->id)->value('club_role'));

        $this->migration->up();
        MemberAssignment::query()->create(['member_id' => $member->id, 'field_key' => 'club_role', 'option_value' => 'Schriftführer', 'starts_on' => '2026-06-01']);
        $this->expectException(RuntimeException::class);
        $this->migration->down();
    }

    private function field(string $key): MemberFieldDefinition
    {
        return MemberFieldDefinition::query()->where('key', $key)->sole();
    }
}
