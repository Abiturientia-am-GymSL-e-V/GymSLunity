<?php

namespace Tests\Feature\Members;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MemberCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_can_open_form_and_create_member(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['mv']]));

        $this->get(route('members.create'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('members/Create')
            ->has('sections')
            ->where('suggestedMemberNumber', 1)
            ->where('can.createMembers', true));

        $response = $this->post(route('members.store'), [
            'member_number' => 4711,
            'configuration_version' => 0,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'membership_type' => 'Fördermitglied',
            'is_honorary' => false,
            'custom_graduation_year' => 2001,
            'application_file' => UploadedFile::fake()->createWithContent('antrag.pdf', "%PDF-1.4\napplication\n%%EOF"),
            'sepa_file' => UploadedFile::fake()->createWithContent('sepa.pdf', "%PDF-1.4\nsepa\n%%EOF"),
        ]);

        $member = Member::query()->where('member_number', 4711)->firstOrFail();
        $response->assertSessionHasNoErrors()->assertRedirect(route('members.show', ['member' => $member->member_number]));
        $this->assertSame('Ada', $member->first_name);
        $this->assertSame(2001, $member->custom_values['custom_graduation_year']);
        $this->assertSame(['application', 'sepa'], DB::table('member_documents')->where('member_id', $member->id)->orderBy('kind')->pluck('kind')->all());
    }

    public function test_creation_validates_permissions_required_fields_and_unique_number(): void
    {
        Member::factory()->create(['member_number' => 4711]);

        $this->actingAs(User::factory()->create(['roles' => ['auditor']]));
        $this->get(route('members.create'))->assertForbidden();
        $this->post(route('members.store'), [])->assertForbidden();

        $this->actingAs(User::factory()->create(['roles' => ['mv']]));
        $this->postJson(route('members.store'), [
            'member_number' => 4712,
            'configuration_version' => 0,
            'is_honorary' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'last_name', 'membership_type']);
        $this->postJson(route('members.store'), [
            'member_number' => 4711,
            'configuration_version' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('member_number');
        $this->assertDatabaseCount('members', 1);
    }
}
