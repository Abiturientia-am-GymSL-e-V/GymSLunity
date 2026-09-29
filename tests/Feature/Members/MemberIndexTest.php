<?php

declare(strict_types=1);

namespace Tests\Feature\Members;

use App\Models\Member;
use App\Models\User;
use Database\Seeders\DemoMembersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\WithSchoolMemberFields;
use Tests\TestCase;

class MemberIndexTest extends TestCase
{
    use RefreshDatabase;
    use WithSchoolMemberFields;

    private function signIn(array $roles = ['mv']): User
    {
        $user = User::factory()->create(['roles' => $roles]);
        $this->actingAs($user);

        return $user;
    }

    public function test_guest_and_unverified_accounts_cannot_read_members(): void
    {
        $this->get(route('members.index'))->assertRedirect(route('login'));
        $user = User::factory()->unverified()->create(['roles' => ['admin']]);
        $this->actingAs($user)->get(route('members.index'))->assertRedirect(route('verification.notice'));
    }

    public function test_member_access_requires_an_explicit_permitted_role(): void
    {
        foreach ([[], ['bh'], ['bm'], ['kp']] as $roles) {
            $this->signIn($roles);
            $this->get(route('members.index'))->assertForbidden();
            $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('can.viewMembers', false));
        }
        foreach ([['admin'], ['vereinsverwaltung'], ['mv'], ['auditor']] as $roles) {
            $this->signIn($roles);
            $this->get(route('members.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.viewMembers', true));
        }
    }

    public function test_profile_changes_cannot_grant_member_permissions(): void
    {
        $user = $this->signIn([]);
        $this->patch(route('profile.update'), [
            'name' => $user->name, 'email' => $user->email, 'roles' => ['admin'],
        ])->assertSessionHasNoErrors();
        $this->assertSame([], $user->fresh()->roles);
        $this->get(route('members.index'))->assertForbidden();
    }

    public function test_list_sorts_names_and_serializes_only_directory_fields(): void
    {
        $this->signIn();
        Member::factory()->create(['first_name' => 'Zoe', 'last_name' => 'Zimmermann']);
        Member::factory()->create(['first_name' => 'Anna', 'last_name' => 'Adam', 'birth_date' => '2000-02-29', 'postal_code' => '01234']);

        $this->get(route('members.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('members/Index')
            ->where('totalMembers', 2)->where('members.total', 2)
            ->has('members.data', 2)
            ->where('members.data.0.first_name', 'Anna')
            ->where('members.data.0.birth_date', '2000-02-29')
            ->where('members.data.0.postal_code', '01234')
            ->where('members.data.0.is_honorary', false)
            ->missing('members.data.0.created_at')->missing('members.data.0.updated_at')
            ->missing('members.data.0.iban')->missing('members.data.0.beitritt_pdf')
            ->missing('members.data.0.mandat_pdf'));
    }

    public function test_combined_search_and_all_four_filters_apply_together(): void
    {
        $this->signIn();
        Member::factory()->create([
            'first_name' => 'Anna', 'last_name' => 'Müller', 'custom_values' => ['custom_graduation_year' => 2015],
            'membership_type' => 'Fördermitglieder', 'department_role' => 'Delegierte', 'club_role' => 'Kassierer',
        ]);
        Member::factory()->create(['first_name' => 'Anna', 'last_name' => 'Müller', 'custom_values' => ['custom_graduation_year' => 2016]]);
        Member::factory()->create(['first_name' => 'Ben', 'last_name' => 'Müller', 'custom_values' => ['custom_graduation_year' => 2015]]);

        $this->get(route('members.index', [
            'q' => 'Müller Anna', 'custom' => ['custom_graduation_year' => '2015'], 'membership' => 'Fördermitglieder',
            'department_role' => 'Delegierte', 'club_role' => 'Kassierer',
        ]))->assertInertia(fn (Assert $page) => $page
            ->where('members.total', 1)->where('totalMembers', 3)
            ->where('members.data.0.first_name', 'Anna')
            ->where('filters.custom.custom_graduation_year', '2015')
            ->where('filterOptions.memberships', ['Ehemalige', 'Fördermitglieder']));
    }

    public function test_search_finds_contact_details_and_member_numbers(): void
    {
        $this->signIn();
        Member::factory()->create(['member_number' => 9000012, 'email' => 'unique@example.invalid', 'postal_code' => '01234', 'city' => 'Beispielhausen']);
        Member::factory()->create(['member_number' => 9000099, 'postal_code' => '99999']);
        foreach (['9000012', 'unique@example.invalid', '01234 Beispielhausen'] as $q) {
            $this->get(route('members.index', ['q' => $q]))->assertInertia(fn (Assert $page) => $page
                ->where('members.total', 1)->where('members.data.0.member_number', 9000012));
        }
    }

    public function test_search_treats_sql_wildcards_and_quotes_as_literal_text(): void
    {
        $this->signIn();
        Member::factory()->create(['last_name' => '100%_Test!', 'email' => null]);
        Member::factory()->create(['last_name' => 'Normal', 'email' => null]);
        foreach (['%', '_', '!'] as $q) {
            $this->get(route('members.index', ['q' => $q]))->assertInertia(fn (Assert $page) => $page->where('members.total', 1));
        }
        $this->get(route('members.index', ['q' => "' OR 1=1 --"]))->assertInertia(fn (Assert $page) => $page->where('members.total', 0));
    }

    public function test_role_presence_filters_include_both_null_and_empty_values(): void
    {
        $this->signIn();
        Member::factory()->create(['department_role' => null, 'club_role' => null]);
        Member::factory()->create(['department_role' => '', 'club_role' => '']);
        Member::factory()->create(['department_role' => 'Delegierte', 'club_role' => 'Kassierer']);

        foreach (['department_role', 'club_role'] as $key) {
            $this->get(route('members.index', [$key => '__none__']))->assertInertia(fn (Assert $page) => $page->where('members.total', 2));
            $this->get(route('members.index', [$key => '__any__']))->assertInertia(fn (Assert $page) => $page->where('members.total', 1));
        }
        $this->get(route('members.index'))->assertInertia(fn (Assert $page) => $page
            ->where('filterOptions.departmentRoles', ['Delegierte'])->where('filterOptions.clubRoles', ['Kassierer']));
    }

    public function test_pagination_is_bounded_and_retains_filters_and_sorting(): void
    {
        $this->signIn();
        Member::factory()->count(32)->sequence(fn ($sequence) => [
            'member_number' => 9000001 + $sequence->index, 'last_name' => 'Gleich', 'first_name' => 'Alex',
        ])->create();
        $this->get(route('members.index', ['q' => 'Gleich', 'per_page' => 10, 'page' => 2, 'sort' => 'member_number', 'direction' => 'desc']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('members.data', 10)->where('members.total', 32)->where('members.current_page', 2)
                ->where('members.data.0.member_number', 9000022)
                ->where('members.data.9.member_number', 9000013)
                ->where('filters.q', 'Gleich')->where('filters.direction', 'desc'));
        $this->get(route('members.index', ['per_page' => 10, 'page' => 999]))
            ->assertInertia(fn (Assert $page) => $page->has('members.data', 2)->where('members.current_page', 4));
    }

    public function test_duplicate_names_have_stable_pagination(): void
    {
        $this->signIn();
        $members = Member::factory()->count(12)->create(['first_name' => 'Alex', 'last_name' => 'Gleich']);
        $this->get(route('members.index', ['per_page' => 10, 'page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('members.data', 2)
                ->where('members.data.0.id', $members[10]->id)->where('members.data.1.id', $members[11]->id));
    }

    public function test_empty_and_filtered_empty_tables_have_valid_metadata(): void
    {
        $this->signIn();
        $this->get(route('members.index'))->assertInertia(fn (Assert $page) => $page
            ->has('members.data', 0)->where('members.total', 0)->where('members.last_page', 1)
            ->has('fieldDefinitions'));
        Member::factory()->create();
        $this->get(route('members.index', ['q' => 'does-not-exist', 'page' => 99]))
            ->assertInertia(fn (Assert $page) => $page->has('members.data', 0)->where('members.current_page', 1)->where('totalMembers', 1));
    }

    public function test_invalid_query_parameters_are_rejected(): void
    {
        $this->signIn();
        foreach ([
            ['sort' => 'password'], ['direction' => 'desc;DROP TABLE members'], ['q' => ['array']],
            ['q' => str_repeat('a', 121)], ['per_page' => 10000], ['custom' => ['custom_graduation_year' => 'invalid']],
            ['department_role' => ['array']], ['page' => -1],
        ] as $query) {
            $this->getJson(route('members.index', $query))->assertUnprocessable()->assertJsonValidationErrors(isset($query['custom']) ? ['custom.custom_graduation_year'] : array_keys($query));
        }
    }

    public function test_the_directory_endpoint_does_not_allow_writes(): void
    {
        $this->signIn(['admin']);
        $this->post(route('members.index'), ['first_name' => 'Invalid'])->assertMethodNotAllowed();
        $this->assertDatabaseCount('members', 0);
    }

    public function test_demo_seeding_is_repeatable_without_overwriting_members(): void
    {
        $this->seed(DemoMembersSeeder::class);
        $this->assertDatabaseCount('members', 20);
        Member::query()->where('member_number', 9000001)->update(['city' => 'Geändert']);
        $this->seed(DemoMembersSeeder::class);
        $this->assertDatabaseCount('members', 20);
        $this->assertDatabaseHas('members', ['member_number' => 9000001, 'city' => 'Geändert']);
        $this->assertDatabaseHas('members', ['postal_code' => '01234']);
    }
}
