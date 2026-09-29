<?php

declare(strict_types=1);

namespace Tests\Feature\Members;

use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\WithSchoolMemberFields;
use Tests\TestCase;

class MemberCardTest extends TestCase
{
    use RefreshDatabase;
    use WithSchoolMemberFields;

    public function test_card_contains_all_current_fields_archived_values_history_and_document_references(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-22 20:15:00 UTC'));
        $actor = User::factory()->create(['roles' => ['mv'], 'name' => 'Frühere Bearbeitung']);
        $this->actingAs($actor);
        Storage::fake('local');
        $logoPath = 'branding/logo-22222222-2222-2222-2222-222222222222.png';
        Storage::disk('local')->put($logoPath, UploadedFile::fake()->image('logo.png', 120, 60)->get());
        ClubSetting::current()->update(['data' => ['name' => 'Turnverein Musterstadt', 'logo_path' => $logoPath]]);
        $member = Member::factory()->create(['city' => 'Alter Ort', 'iban' => 'DE89370400440532013000', 'custom_values' => ['custom_graduation_year' => 2010, 'custom_graduation' => 'Abitur']]);
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
        $this->assertStringContainsString('<img class="report-logo" src="data:image/png;base64,', $print);
        $this->assertStringContainsString('DE89 3704 0044 0532 0130 00', $print);
        $this->assertStringContainsString('22.09.2026 22:15 CEST', $print);
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
