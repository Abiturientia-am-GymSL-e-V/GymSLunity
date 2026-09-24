<?php

namespace Tests\Feature\SelfService;

use App\Mail\SelfServiceAccessMail;
use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\User;
use App\SelfService\FormTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private function enable(bool $join = true): void
    {
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'selfservice_enabled' => true, 'public_join_enabled' => $join, 'name' => 'Testverein', 'creditor_id' => 'DE98ZZZ09999999999']]);
    }

    private function signIn(Member $member): self
    {
        return $this->withSession(['selfservice' => ['email' => strtolower($member->email), 'member_id' => $member->id, 'until' => time() + 1800]]);
    }

    private function token(string $email, ?Member $member = null, string $purpose = 'login'): string
    {
        $token = bin2hex(random_bytes(32));
        DB::table('selfservice_tokens')->insert(['token_hash' => hash('sha256', $token), 'member_id' => $member?->id, 'email' => $email, 'purpose' => $purpose, 'expires_at' => now()->addMinutes(15)]);

        return $token;
    }

    private function signature(): string
    {
        $image = imagecreatetruecolor(100, 40);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imageline($image, 10, 10, 80, 30, imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($png);
    }

    private function application(): array
    {
        return ['version' => 0, 'first_name' => 'Ada', 'last_name' => 'Test', 'birth_date' => '1990-01-01', 'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt', 'country' => 'DE', 'membership_type' => 'Fördermitglied', 'accepted' => true, 'signature' => $this->signature()];
    }

    public function test_disabled_feature_blocks_every_public_endpoint_and_hides_entry(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('selfserviceEnabled', false));
        $this->get('/selfservice/zugang')->assertNotFound();
        $this->post('/selfservice/zugang/anfordern')->assertNotFound();
        $this->get('/selfservice/dokumente/sepa')->assertNotFound();
        $this->post('/selfservice/formulare/application')->assertNotFound();
    }

    public function test_member_access_is_separate_from_administration_and_payload_is_minimal(): void
    {
        $this->enable();
        $member = Member::factory()->create(['iban' => 'DE89370400440532013000', 'club_role' => 'Geheim']);
        $this->signIn($member)->get('/selfservice')->assertOk()->assertInertia(fn (Assert $page) => $page->where('member.member_number', $member->member_number)->missing('member.iban')->missing('member.club_role')->where('auth.user', null));
        $this->get('/mitglieder')->assertRedirect('/login');
        $this->get('/konfiguration/selfservice')->assertRedirect('/login');
    }

    public function test_tokens_are_hashed_expire_and_are_single_use(): void
    {
        $this->enable();
        Mail::fake();
        $member = Member::factory()->create();
        $this->post('/selfservice/zugang/anfordern', ['email' => $member->email, 'purpose' => 'login'])->assertRedirect();
        $mail = Mail::sent(SelfServiceAccessMail::class)->first();
        $this->assertDatabaseHas('selfservice_tokens', ['token_hash' => hash('sha256', $mail->token)]);
        $this->assertDatabaseMissing('selfservice_tokens', ['token_hash' => $mail->token]);
        $this->post('/selfservice/zugang/bestaetigen', ['token' => $mail->token])->assertRedirect('/selfservice');
        $this->postJson('/selfservice/zugang/bestaetigen', ['token' => $mail->token])->assertUnprocessable();
        $token = $this->token($member->email, $member);
        $this->travel(16)->minutes();
        $this->postJson('/selfservice/zugang/bestaetigen', ['token' => $token])->assertUnprocessable();
    }

    public function test_unknown_and_ambiguous_emails_do_not_receive_login_tokens(): void
    {
        $this->enable();
        Mail::fake();
        $this->post('/selfservice/zugang/anfordern', ['email' => 'unknown@example.com', 'purpose' => 'login'])->assertRedirect();
        Member::factory()->count(2)->create(['email' => 'shared@example.com']);
        $this->post('/selfservice/zugang/anfordern', ['email' => 'shared@example.com', 'purpose' => 'login'])->assertRedirect();
        Mail::assertNothingSent();
        $member = Member::query()->where('email', 'shared@example.com')->first();
        $this->post('/selfservice/zugang/anfordern', ['email' => 'shared@example.com', 'member_number' => $member->member_number, 'purpose' => 'login']);
        Mail::assertSentCount(1);
    }

    public function test_changed_member_email_and_expired_sessions_revoke_access(): void
    {
        $this->enable();
        $member = Member::factory()->create();
        $token = $this->token($member->email, $member);
        $this->signIn($member);
        $member->update(['email' => 'new@example.com']);
        $this->get('/selfservice')->assertUnauthorized();
        $this->post('/selfservice/zugang/bestaetigen', ['token' => $token])->assertForbidden();
        $this->signIn($member);
        $this->travel(31)->minutes();
        $this->get('/selfservice')->assertUnauthorized();
    }

    public function test_profile_allowlist_history_and_stale_write_protection(): void
    {
        $this->enable();
        $member = Member::factory()->create();
        $this->signIn($member)->patch('/selfservice/profil', [...$this->application(), 'lock_version' => 0, 'member_number' => 999, 'email' => 'attacker@example.com', 'club_role' => 'Vorstand'])->assertSessionHasNoErrors();
        $member->refresh();
        $this->assertSame('Ada', $member->first_name);
        $this->assertNotSame('attacker@example.com', $member->email);
        $this->assertNull($member->club_role);
        $this->assertDatabaseHas('member_changes', ['member_id' => $member->id, 'version' => 1, 'actor_id' => null]);
        $this->patchJson('/selfservice/profil', [...$this->application(), 'lock_version' => 0])->assertUnprocessable()->assertJsonValidationErrors('lock_version');
    }

    public function test_documents_are_scoped_to_session_member(): void
    {
        $this->enable();
        $a = Member::factory()->create();
        $b = Member::factory()->create();
        DB::table('member_documents')->insert(['member_id' => $b->id, 'kind' => 'sepa', 'contents' => '%PDF-1.4 private', 'created_at' => now(), 'updated_at' => now()]);
        $this->signIn($a)->get('/selfservice/dokumente/sepa?member_id='.$b->id)->assertNotFound();
        $this->get('/selfservice/dokumente/unknown')->assertNotFound();
        $this->signIn($b)->get('/selfservice/dokumente/sepa')->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertSee('private');
    }

    public function test_public_join_requires_verified_email_and_creates_pdf_and_account_once(): void
    {
        $this->enable();
        $this->postJson('/selfservice/formulare/application', $this->application())->assertUnauthorized();
        $token = $this->token('new@example.com', null, 'join');
        $this->post('/selfservice/zugang/bestaetigen', ['token' => $token])->assertRedirect('/selfservice/beitritt');
        $this->get('/selfservice/beitritt')->assertOk();
        $this->post('/selfservice/formulare/application', $this->application())->assertSessionHasNoErrors()->assertRedirect('/selfservice');
        $member = Member::query()->sole();
        $this->assertSame('new@example.com', $member->email);
        $this->assertNotNull($member->joined_at);
        $this->assertDatabaseHas('contribution_accounts', ['member_id' => $member->id]);
        $this->get('/selfservice/dokumente/application')->assertOk()->assertSee('%PDF-', false);
        $this->post('/selfservice/formulare/application', $this->application())->assertForbidden();
        $this->assertDatabaseCount('members', 1);
        $this->assertDatabaseCount('member_documents', 1);
    }

    public function test_existing_contact_joins_without_duplicate_and_minor_needs_guardian(): void
    {
        $this->enable(false);
        $member = Member::factory()->create(['membership_type' => 'Kontakt', 'joined_at' => null]);
        $values = [...$this->application(), 'lock_version' => 0, 'birth_date' => now()->subYears(15)->toDateString()];
        $this->signIn($member)->postJson('/selfservice/formulare/application', $values)->assertUnprocessable()->assertJsonValidationErrors('guardian_signature');
        $this->post('/selfservice/formulare/application', [...$values, 'guardian_name' => 'Elternteil Test', 'guardian_signature' => $this->signature()])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('members', 1);
        $this->assertSame('Fördermitglied', $member->fresh()->membership_type);
        $this->assertNotNull($member->fresh()->joined_at);
    }

    public function test_mandate_validates_iban_and_persists_sepa_fields_and_pdf(): void
    {
        $this->enable();
        $member = Member::factory()->create();
        $values = ['version' => 0, 'lock_version' => 0, 'accepted' => true, 'signature' => $this->signature(), 'iban' => 'invalid', 'account_holder_first_name' => 'Ada', 'account_holder_last_name' => 'Test', 'account_holder_street' => 'Straße 1', 'account_holder_postal_code' => '12345', 'account_holder_city' => 'Ort', 'account_holder_country' => 'DE'];
        $this->signIn($member)->postJson('/selfservice/formulare/sepa', $values)->assertUnprocessable()->assertJsonValidationErrors('iban');
        $values['iban'] = 'DE89370400440532013000';
        $this->post('/selfservice/formulare/sepa', $values)->assertSessionHasNoErrors()->assertRedirect('/selfservice');
        $this->assertSame('SEPA-Lastschrift', $member->fresh()->payment_method);
        $this->assertNotNull($member->fresh()->mandate_reference);
        $this->assertDatabaseHas('member_documents', ['member_id' => $member->id, 'kind' => 'sepa', 'submitted_online' => true]);
        $this->get('/selfservice/mandat')->assertConflict();
    }

    public function test_email_change_requires_confirmation_in_existing_member_session(): void
    {
        $this->enable();
        Mail::fake();
        $member = Member::factory()->create();
        $old = $member->email;
        $this->signIn($member)->post('/selfservice/zugang/anfordern', ['email' => 'new@example.com', 'purpose' => 'email'])->assertRedirect();
        $this->assertSame($old, $member->fresh()->email);
        $mail = Mail::sent(SelfServiceAccessMail::class)->first();
        $this->post('/selfservice/zugang/bestaetigen', ['token' => $mail->token])->assertRedirect('/selfservice');
        $this->assertSame('new@example.com', $member->fresh()->email);
        $this->assertDatabaseHas('member_changes', ['member_id' => $member->id, 'version' => 1]);
    }

    public function test_configuration_is_admin_only_and_placeholders_are_validated(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['mv']]))->get('/konfiguration/selfservice')->assertForbidden();
        $this->actingAs(User::factory()->create(['roles' => ['admin']]));
        $this->get('/konfiguration/selfservice')->assertInertia(fn (Assert $page) => $page
            ->where('defaults.receipt_notes', FormTemplates::defaults()['receipt_notes'])
            ->where('defaults.receipt_donation_notes', FormTemplates::defaults()['receipt_donation_notes'])
            ->where('settings.receipt_notes', FormTemplates::defaults()['receipt_notes'])
            ->where('settings.receipt_donation_notes', FormTemplates::defaults()['receipt_donation_notes'])
            ->where('placeholders', fn ($placeholders): bool => $placeholders->contains('{{verein.tax_privilege_notice}}')));
        $values = ['version' => 0, 'selfservice_enabled' => true, 'public_join_enabled' => true, 'membership_activation' => 'immediate', ...FormTemplates::defaults()];
        $this->patchJson('/konfiguration/selfservice', [...$values, 'sepa_text' => '{{unknown}}'])->assertUnprocessable();
        $this->patch('/konfiguration/selfservice', $values)->assertSessionHasNoErrors();
        $this->assertTrue(ClubSetting::current()->data['selfservice_enabled']);
        $this->patchJson('/konfiguration/selfservice', $values)->assertUnprocessable()->assertJsonValidationErrors('version');
    }

    public function test_public_join_switch_and_template_version_are_enforced(): void
    {
        $this->enable(false);
        $this->post('/selfservice/zugang/anfordern', ['email' => 'new@example.com', 'purpose' => 'join'])->assertForbidden();
        $this->enable();
        $this->post('/selfservice/zugang/bestaetigen', ['token' => $this->token('new@example.com', null, 'join')]);
        ClubSetting::current()->update(['version' => 1]);
        $this->postJson('/selfservice/formulare/application', $this->application())->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertDatabaseCount('members', 0);
    }

    public function test_email_request_limit_applies_across_sessions(): void
    {
        $this->enable();
        Mail::fake();
        $member = Member::factory()->create();
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->flushSession();
            $this->post('/selfservice/zugang/anfordern', ['email' => $member->email, 'purpose' => 'login'])->assertRedirect();
        }
        Mail::assertSentCount(3);
    }

    public function test_email_confirmation_cannot_change_another_member(): void
    {
        $this->enable();
        $owner = Member::factory()->create();
        $other = Member::factory()->create();
        $token = $this->token('replacement@example.com', $owner, 'email');
        $this->signIn($other)->post('/selfservice/zugang/bestaetigen', ['token' => $token])->assertForbidden();
        $this->assertNotSame('replacement@example.com', $owner->fresh()->email);
        $this->assertDatabaseHas('selfservice_tokens', ['token_hash' => hash('sha256', $token)]);
    }

    public function test_get_does_not_consume_token_and_logout_removes_member_access(): void
    {
        $this->enable();
        $member = Member::factory()->create();
        $token = $this->token($member->email, $member);
        $this->get('/selfservice/zugang?token='.$token)->assertOk();
        $this->assertDatabaseHas('selfservice_tokens', ['token_hash' => hash('sha256', $token)]);
        $this->get('/selfservice')->assertUnauthorized();
        $this->signIn($member)->post('/selfservice/abmelden')->assertRedirect('/');
        $this->get('/selfservice')->assertUnauthorized();
    }

    public function test_public_application_waits_for_approval_and_keeps_original_document(): void
    {
        $this->enable();
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'membership_activation' => 'approval']]);
        $this->post('/selfservice/zugang/bestaetigen', ['token' => $this->token('applicant@example.com', null, 'join')]);
        $this->get('/selfservice/beitritt')->assertInertia(fn (Assert $page) => $page->where('requiresApproval', true));
        $this->post('/selfservice/formulare/application', $this->application())->assertSessionHasNoErrors();
        $member = Member::query()->sole();
        $this->assertSame('Kontakt', $member->membership_type);
        $this->assertNull($member->joined_at);
        $this->assertDatabaseHas('membership_applications', ['member_id' => $member->id, 'membership_type' => 'Fördermitglied', 'approved_at' => null]);
        $this->get('/selfservice')->assertInertia(fn (Assert $page) => $page->where('canJoin', false)->where('pendingApplication.membership_type', 'Fördermitglied'));
        $this->get('/selfservice/mandat')->assertForbidden();
        $this->post('/selfservice/formulare/application', $this->application())->assertForbidden();
        $pdf = $this->get('/selfservice/dokumente/application')->assertOk()->getContent();
        // Configuration changes never implicitly approve an existing application.
        $settings->update(['data' => [...$settings->data, 'membership_activation' => 'immediate']]);
        $this->assertNull($member->fresh()->joined_at);
        $admin = User::factory()->create(['roles' => ['mv']]);
        $this->actingAs($admin)->get('/mitglieder/antraege')->assertOk()->assertInertia(fn (Assert $page) => $page->where('applications.total', 1));
        $this->post('/mitglieder/'.$member->member_number.'/beitritt-freigeben', ['lock_version' => $member->lock_version])->assertSessionHasNoErrors();
        $this->assertSame('Fördermitglied', $member->fresh()->membership_type);
        $this->assertSame(now()->toDateString(), $member->fresh()->joined_at->toDateString());
        $this->assertDatabaseHas('membership_applications', ['member_id' => $member->id, 'approved_by' => $admin->id]);
        $this->assertDatabaseHas('member_changes', ['member_id' => $member->id, 'actor_id' => $admin->id, 'version' => $member->lock_version + 1]);
        $this->get('/selfservice/mandat')->assertOk();
        $this->assertSame($pdf, $this->get('/selfservice/dokumente/application')->getContent());
        $this->post('/mitglieder/'.$member->member_number.'/beitritt-freigeben', ['lock_version' => $member->fresh()->lock_version])->assertConflict();
    }

    public function test_contact_approval_requires_editor_permission_and_current_member_version(): void
    {
        $this->enable(false);
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'membership_activation' => 'approval']]);
        $member = Member::factory()->create(['membership_type' => 'Kontakt', 'joined_at' => null]);
        $this->signIn($member)->post('/selfservice/formulare/application', [...$this->application(), 'lock_version' => 0])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('members', 1);
        $url = '/mitglieder/'.$member->member_number.'/beitritt-freigeben';
        $this->post($url, ['lock_version' => 1])->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['roles' => ['auditor']]))->get('/mitglieder/antraege')->assertForbidden();
        $this->post($url, ['lock_version' => 1])->assertForbidden();
        $this->actingAs(User::factory()->create(['roles' => ['admin']]));
        $this->postJson($url, ['lock_version' => 0])->assertUnprocessable()->assertJsonValidationErrors('lock_version');
        $this->assertNull($member->fresh()->joined_at);
        $this->assertDatabaseHas('membership_applications', ['member_id' => $member->id, 'approved_at' => null]);
    }

    public function test_activation_mode_is_configurable_and_rejects_unknown_values(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['admin']]));
        $this->get('/konfiguration/selfservice')->assertInertia(fn (Assert $page) => $page->where('settings.membership_activation', 'immediate'));
        $values = ['version' => 0, 'selfservice_enabled' => true, 'public_join_enabled' => true, 'membership_activation' => 'invalid', ...FormTemplates::defaults()];
        $this->patchJson('/konfiguration/selfservice', $values)->assertUnprocessable()->assertJsonValidationErrors('membership_activation');
        $this->patch('/konfiguration/selfservice', [...$values, 'membership_activation' => 'approval'])->assertSessionHasNoErrors();
        $this->assertSame('approval', ClubSetting::current()->data['membership_activation']);
    }
}
