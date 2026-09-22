<?php

namespace Tests\Feature\Members;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MemberCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_card_contains_all_current_fields_archived_values_history_and_document_references(): void
    {
        $actor = User::factory()->create(['roles' => ['mv'], 'name' => 'Frühere Bearbeitung']);
        $this->actingAs($actor);
        $member = Member::factory()->create(['city' => 'Alter Ort', 'custom_values' => ['custom_graduation_year' => 2010, 'custom_graduation' => 'Abitur']]);
        DB::table('member_documents')->insert(['member_id' => $member->id, 'kind' => 'application', 'contents' => '%PDF-1.4 private', 'submitted_online' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->patch(route('members.update', $member->member_number), ['lock_version' => 0, 'city' => 'Neuer Ort'])->assertSessionHasNoErrors();
        $actor->delete();
        $this->app['auth']->logout();
        $url = route('members.card', $member->member_number);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['roles' => ['auditor']]));
        $print = $this->get($url)->assertOk()->getContent();
        foreach (['Karteiblatt', 'Neuer Ort', 'Alter Ort', 'Abitur', '2010', 'Mitgliedsantrag', 'Frühere Bearbeitung', 'IBAN', 'Änderungshistorie'] as $value) {
            $this->assertStringContainsString($value, $print);
        }
        $this->assertStringNotContainsString('%PDF-1.4 private', $print);
        $this->assertDatabaseHas('member_changes', ['actor_id' => null, 'actor_name' => 'Frühere Bearbeitung']);
        $pdf = $this->get(route('members.card', ['member' => $member->member_number, 'format' => 'pdf']))->assertOk();
        $pdf->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
    }

    public function test_card_requires_member_read_permission_and_bounded_format(): void
    {
        $member = Member::factory()->create();
        $url = route('members.card', $member->member_number);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['roles' => ['bh']]))->get($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['roles' => ['mv']]))->getJson(route('members.card', ['member' => $member->member_number, 'format' => 'secret']))->assertUnprocessable();
    }
}
