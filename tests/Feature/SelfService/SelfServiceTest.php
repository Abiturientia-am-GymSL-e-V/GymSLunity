<?php

declare(strict_types=1);

namespace Tests\Feature\SelfService;

use App\Mail\MembershipCancellationConfirmedMail;
use App\Mail\MembershipWelcomeMail;
use App\Mail\MemberWelcomeMail;
use App\Mail\SelfServiceAccessMail;
use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\MemberFieldDefinition;
use App\Models\User;
use App\PublicSite\PublicPageTemplates;
use App\Security\MemberDocumentStore;
use App\SelfService\FormTemplates;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
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
        return ['version' => 0, 'first_name' => 'Ada', 'last_name' => 'Test', 'gender' => 'w', 'birth_date' => '1990-01-01', 'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt', 'country' => 'DE', 'membership_type' => 'Fördermitglied', 'sponsor_contribution' => '25.00', 'payment_method' => 'Überweisung', 'accepted' => true, 'signature' => $this->signature()];
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

    public function test_unauthorized_page_links_back_to_home(): void
    {
        $this->enable();

        $this->get('/selfservice')
            ->assertUnauthorized()
            ->assertSee('Nicht autorisiert')
            ->assertSee('Dein Zugang ist abgelaufen. Bitte fordere einen neuen E-Mail-Link an.')
            ->assertSee('Zur Startseite')
            ->assertSee('href="'.route('home').'"', false);
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

    public function test_member_number_alone_can_request_access(): void
    {
        $this->enable();
        Mail::fake();
        $member = Member::factory()->create();

        $this->post('/selfservice/zugang/anfordern', [
            'member_number' => $member->member_number,
            'purpose' => 'login',
        ])->assertRedirect()
            ->assertInertiaFlash('toast.type', 'info');

        Mail::assertSent(SelfServiceAccessMail::class, fn (SelfServiceAccessMail $mail): bool => $mail->hasTo($member->email));
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
        $this->assertSame('w', $member->gender);
        $this->assertNotSame('attacker@example.com', $member->email);
        $this->assertNull($member->club_role);
        $this->assertDatabaseHas('member_changes', ['member_id' => $member->id, 'version' => 1, 'actor_id' => null]);
        $this->patchJson('/selfservice/profil', [...$this->application(), 'lock_version' => 0])->assertUnprocessable()->assertJsonValidationErrors('lock_version');
    }

    public function test_configured_member_fields_membership_and_payment_are_editable_in_portal(): void
    {
        $this->enable();
        $custom = MemberFieldDefinition::query()->create([
            'key' => 'custom_favorite_team', 'label' => 'Lieblingsteam', 'type' => 'text',
            'section' => 'additional', 'position' => 10, 'is_active' => true, 'is_custom' => true,
            'required' => false, 'filterable' => false, 'show_in_table' => false,
            'selfservice_visible' => true, 'selfservice_editable' => true, 'options' => [], 'max_length' => 100,
        ]);
        $member = Member::factory()->create([
            'membership_type' => 'Aktiv/ordentliches Mitglied',
            'payment_method' => 'Bar',
            'gender' => null,
        ]);

        $this->signIn($member)->get('/selfservice')->assertInertia(function (Assert $page) use ($custom): void {
            $page->where('isActiveMember', true)
                ->where('calendarEnabled', true)
                ->where('calendarSubscription', null)
                ->where('profileSections', function ($sections) use ($custom): bool {
                    $keys = $sections->flatMap(fn ($section) => $section['fields'])->pluck('key');

                    return $keys->contains('gender')
                        && $keys->contains('membership_type')
                        && $keys->contains('sponsor_contribution')
                        && $keys->contains('payment_method')
                        && $keys->contains($custom->key)
                        && ! $keys->contains('club_role');
                });
        });

        $this->patch('/selfservice/profil', [
            'lock_version' => 0,
            'gender' => 'd',
            'membership_type' => 'Fördermitglied',
            'sponsor_contribution' => '37,50',
            'payment_method' => 'Überweisung',
            $custom->key => 'Nordstadt',
            'club_role' => 'Vorstand',
        ])->assertSessionHasNoErrors();

        $member->refresh();
        $this->assertSame('d', $member->gender);
        $this->assertSame('Fördermitglied', $member->membership_type);
        $this->assertSame('37.50', $member->sponsor_contribution);
        $this->assertSame('Überweisung', $member->payment_method);
        $this->assertSame('Nordstadt', $member->custom_values[$custom->key]);
        $this->assertNull($member->club_role);
        $this->assertDatabaseHas('member_changes', ['member_id' => $member->id, 'version' => 1]);
    }

    public function test_visible_but_not_editable_fields_are_read_only_in_portal(): void
    {
        $this->enable();
        MemberFieldDefinition::query()->where('key', 'birth_date')->update([
            'selfservice_visible' => true,
            'selfservice_editable' => false,
        ]);
        $member = Member::factory()->create(['birth_date' => '2000-01-02']);

        $this->signIn($member)->get('/selfservice')->assertInertia(function (Assert $page): void {
            $page->where('profileSections', function ($sections): bool {
                $birthDate = $sections
                    ->flatMap(fn ($section) => $section['fields'])
                    ->firstWhere('key', 'birth_date');

                return $birthDate !== null && $birthDate['readOnly'] === true;
            });
        });

        $this->patch('/selfservice/profil', [
            'lock_version' => 0,
            'birth_date' => '1999-12-31',
        ])->assertSessionHasNoErrors();

        $this->assertSame('2000-01-02', $member->fresh()->birth_date->format('Y-m-d'));
    }

    public function test_changing_away_from_sepa_revokes_and_hides_the_mandate_but_keeps_admin_history(): void
    {
        $this->enable();
        $member = Member::factory()->create([
            'payment_method' => 'SEPA-Lastschrift',
            'iban' => 'DE89370400440532013000',
            'mandate_reference' => 'M100-OLD',
            'mandate_signed_at' => '2026-01-10',
            'account_holder_first_name' => 'Ada',
            'account_holder_last_name' => 'Test',
        ]);
        app(MemberDocumentStore::class)->store(
            $member->id,
            'sepa',
            '%PDF-1.4 historic mandate',
            true,
            ['mandate_reference' => 'M100-OLD', 'mandate_signed_at' => '2026-01-10'],
        );

        $this->signIn($member)->patch('/selfservice/profil', [
            'lock_version' => 0,
            'payment_method' => 'Überweisung',
        ])->assertSessionHasNoErrors();

        $member->refresh();
        $this->assertSame('Überweisung', $member->payment_method);
        foreach (['iban', 'mandate_reference', 'mandate_signed_at', 'account_holder_first_name', 'account_holder_last_name'] as $field) {
            $this->assertNull($member->getAttribute($field));
        }
        $mandate = DB::table('member_documents')->where('member_id', $member->id)->where('kind', 'sepa')->sole();
        $this->assertNotNull($mandate->revoked_at);
        $this->assertStringContainsString('Zahlungsart geändert', $mandate->revocation_reason);
        $this->get('/selfservice')->assertInertia(fn (Assert $page) => $page
            ->where('member.payment_method', 'Überweisung')
            ->where('documents', fn ($documents): bool => ! $documents->contains('sepa')));
        $this->get('/selfservice/dokumente/sepa')->assertNotFound();

        $this->actingAs(User::factory()->create(['roles' => ['mv']]))
            ->get(route('members.show', $member->member_number))
            ->assertInertia(fn (Assert $page) => $page
                ->has('mandates', 1)
                ->where('mandates.0.mandate_reference', 'M100-OLD')
                ->where('mandates.0.active', false)
                ->where('mandates.0.revoked_at', fn ($value): bool => is_string($value)));
        $this->get(route('members.mandates.document', [
            'member' => $member->member_number,
            'document' => $mandate->id,
        ]))->assertOk()->assertContent('%PDF-1.4 historic mandate');
    }

    public function test_member_can_request_cancellation_and_administration_confirms_it(): void
    {
        $this->enable();
        Mail::fake();
        $member = Member::factory()->create(['left_at' => null]);
        $leftAt = now()->addMonth()->toDateString();

        $this->signIn($member)->patch('/selfservice/mitgliedschaft/kuendigen', [
            'lock_version' => 0,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertNull($member->fresh()->left_at);
        $this->assertDatabaseHas('membership_cancellations', [
            'member_id' => $member->id,
            'confirmed_at' => null,
            'exit_date' => null,
        ]);
        $this->get('/selfservice')->assertInertia(fn (Assert $page) => $page
            ->where('isActiveMember', true)
            ->where('canRequestCancellation', false)
            ->has('pendingCancellation'));
        $this->get('/selfservice/mandat')->assertOk();
        $this->delete('/selfservice/mitgliedschaft/kuendigen', [
            'lock_version' => 0,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('membership_cancellations', [
            'member_id' => $member->id,
            'confirmed_at' => null,
        ]);
        $this->assertNotNull(
            DB::table('membership_cancellations')
                ->where('member_id', $member->id)
                ->value('withdrawn_at'),
        );
        $this->get('/selfservice')->assertInertia(fn (Assert $page) => $page
            ->where('canRequestCancellation', true)
            ->where('pendingCancellation', null));
        $this->patch('/selfservice/mitgliedschaft/kuendigen', [
            'lock_version' => 0,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->patchJson('/selfservice/mitgliedschaft/kuendigen', [
            'lock_version' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('cancellation');

        $this->get('/mitglieder/kuendigungen')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['roles' => ['auditor']]))
            ->get('/mitglieder/kuendigungen')
            ->assertForbidden();

        $admin = User::factory()->create(['roles' => ['mv']]);
        $this->actingAs($admin)
            ->get('/mitglieder/kuendigungen')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('totalMembers', 1)
                ->where('cancellations.total', 1)
                ->where('cancellations.data.0.member_number', $member->member_number));

        $url = '/mitglieder/'.$member->member_number.'/kuendigung-bestaetigen';
        $this->patchJson($url, [
            'lock_version' => 0,
            'exit_date' => now()->subDay()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('exit_date');
        $this->patchJson($url, [
            'lock_version' => 99,
            'exit_date' => $leftAt,
        ])->assertUnprocessable()->assertJsonValidationErrors('lock_version');

        $this->patch($url, [
            'lock_version' => 0,
            'exit_date' => $leftAt,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($leftAt, $member->fresh()->left_at->toDateString());
        $this->assertDatabaseHas('member_changes', [
            'member_id' => $member->id,
            'actor_id' => $admin->id,
            'version' => 1,
        ]);
        $this->assertDatabaseHas('membership_cancellations', [
            'member_id' => $member->id,
            'exit_date' => $leftAt,
            'confirmed_by' => $admin->id,
            'email_error' => null,
        ]);
        Mail::assertSent(
            MembershipCancellationConfirmedMail::class,
            function (MembershipCancellationConfirmedMail $mail) use ($leftAt, $member): bool {
                $mail->assertSeeInHtml(
                    CarbonImmutable::parse($leftAt)->format('d.m.Y'),
                );
                $mail->assertSeeInHtml('an den Vorstand');

                return $mail->hasTo($member->email)
                    && $mail->exitDate === $leftAt;
            },
        );

        $this->signIn($member)->get('/selfservice')->assertInertia(fn (Assert $page) => $page
            ->where('isActiveMember', true)
            ->where('canJoin', false)
            ->where('canRequestCancellation', false)
            ->where('pendingCancellation', null)
            ->where('member.left_at', $leftAt));
        $this->get('/selfservice/mandat')->assertOk();
        $this->actingAs($admin)->patch($url, [
            'lock_version' => 1,
            'exit_date' => $leftAt,
        ])->assertConflict();
    }

    public function test_scheduled_cancellation_can_be_withdrawn(): void
    {
        $this->enable();
        $exitDate = now()->addMonth()->toDateString();
        $member = Member::factory()->create(['left_at' => $exitDate]);
        $cancellationId = DB::table('membership_cancellations')->insertGetId([
            'member_id' => $member->id,
            'requested_at' => now()->subDay(),
            'exit_date' => $exitDate,
            'confirmed_at' => now(),
        ]);

        $this->signIn($member)->delete('/selfservice/mitgliedschaft/kuendigen', [
            'lock_version' => 0,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $member->refresh();
        $this->assertNull($member->left_at);
        $this->assertSame(1, $member->lock_version);
        $this->assertDatabaseHas('member_changes', [
            'member_id' => $member->id,
            'version' => 1,
        ]);
        $this->assertNotNull(
            DB::table('membership_cancellations')
                ->where('id', $cancellationId)
                ->value('withdrawn_at'),
        );
        $this->get('/selfservice')->assertInertia(fn (Assert $page) => $page
            ->where('isActiveMember', true)
            ->where('canRequestCancellation', true)
            ->where('member.left_at', null));
    }

    public function test_completed_membership_can_submit_a_new_application(): void
    {
        $this->enable();
        Mail::fake();
        $member = Member::factory()->create([
            'left_at' => now()->subDay()->toDateString(),
        ]);
        app(MemberDocumentStore::class)->store(
            $member->id,
            'application',
            '%PDF-1.4 previous application',
            true,
        );

        $this->signIn($member)->get('/selfservice')->assertInertia(fn (Assert $page) => $page
            ->where('isActiveMember', false)
            ->where('canJoin', true)
            ->where('member.left_at', now()->subDay()->toDateString()));
        $this->get('/selfservice/mandat')->assertForbidden();
        $this->get('/selfservice/beitritt')->assertOk();
        $this->post('/selfservice/formulare/application', [
            ...$this->application(),
            'lock_version' => 0,
        ])->assertSessionHasNoErrors()->assertRedirect('/selfservice');

        $member->refresh();
        $this->assertNull($member->left_at);
        $this->assertSame(now()->toDateString(), $member->joined_at->toDateString());
        $this->assertSame('Fördermitglied', $member->membership_type);
        $this->assertDatabaseCount('members', 1);
        $this->assertDatabaseCount('member_documents', 1);
        $this->get('/selfservice')->assertInertia(fn (Assert $page) => $page
            ->where('isActiveMember', true)
            ->where('canJoin', false));
    }

    public function test_contribution_account_offers_girocode_for_open_transfer_balance(): void
    {
        $this->enable();
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data,
            'account_holder' => 'Testverein e. V.',
            'iban' => 'DE89370400440532013000',
            'bic' => 'COBADEFFXXX',
        ]]);
        $member = Member::factory()->create(['payment_method' => 'Überweisung']);
        $account = $member->contributionAccount;
        $account->update(['balance_cents' => 12345]);
        $account->transactions()->create([
            'actor_name' => 'Verwaltung', 'kind' => 'charge', 'amount_cents' => 12345,
            'booking_date' => now()->toDateString(), 'description' => 'Jahresbeitrag',
            'reference' => 'B-2026-1', 'created_at' => now(),
        ]);

        $this->signIn($member)->get('/selfservice')->assertInertia(fn (Assert $page) => $page
            ->where('contributionAccount.balance_cents', 12345)
            ->where('contributionAccount.giroCode.amount', '123.45')
            ->where('contributionAccount.giroCode.iban', 'DE89 3704 0044 0532 0130 00')
            ->where('contributionAccount.giroCode.purpose', 'Mitgliedsbeitrag Mitglied '.$member->member_number)
            ->where('contributionAccount.giroCode.image', fn (string $value): bool => str_starts_with($value, 'data:image/svg+xml;base64,'))
            ->where('contributionAccount.transactions.0.description', 'Jahresbeitrag')
            ->missing('contributionAccount.transactions.0.actor_name'));
    }

    public function test_documents_are_scoped_to_session_member(): void
    {
        $this->enable();
        $a = Member::factory()->create();
        $b = Member::factory()->create([
            'payment_method' => 'SEPA-Lastschrift',
            'mandate_reference' => 'M-SCOPED',
            'mandate_signed_at' => now()->toDateString(),
        ]);
        DB::table('member_documents')->insert([
            'member_id' => $b->id, 'kind' => 'sepa', 'mandate_reference' => 'M-SCOPED',
            'mandate_signed_at' => now()->toDateString(), 'contents' => '%PDF-1.4 private',
            'created_at' => now(), 'updated_at' => now(),
        ]);
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
        $this->post('/selfservice/formulare/application', [
            ...$this->application(),
            'mobile_phone' => '+49 0170 1234567',
        ])->assertSessionHasNoErrors()->assertRedirect('/selfservice');
        $member = Member::query()->sole();
        $this->assertSame('new@example.com', $member->email);
        $this->assertSame('+49 170 1234567', $member->mobile_phone);
        $this->assertSame('w', $member->gender);
        $this->assertSame('25.00', $member->sponsor_contribution);
        $this->assertNotNull($member->joined_at);
        $this->assertDatabaseHas('contribution_accounts', ['member_id' => $member->id]);
        $this->get('/selfservice/dokumente/application')->assertOk()->assertSee('%PDF-', false);
        $this->post('/selfservice/formulare/application', $this->application())->assertForbidden();
        $this->assertDatabaseCount('members', 1);
        $this->assertDatabaseCount('member_documents', 1);
    }

    public function test_membership_application_sends_configurable_welcome_mail_with_pdf(): void
    {
        $this->enable();
        Mail::fake();
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data,
            'welcome_mail_subject' => 'Dein Antrag bei {{verein.name}}',
            'welcome_mail_text' => 'Individuell konfiguriert für {{verein.name}}.',
        ]]);

        $this->post('/selfservice/zugang/bestaetigen', [
            'token' => $this->token('applicant@example.com', null, 'join'),
        ]);
        $this->post('/selfservice/formulare/application', $this->application())
            ->assertSessionHasNoErrors()
            ->assertRedirect('/selfservice');

        $member = Member::query()->sole();
        Mail::assertSent(MembershipWelcomeMail::class, function (MembershipWelcomeMail $mail) use ($member): bool {
            $mail->assertTo('applicant@example.com')
                ->assertHasSubject('Dein Antrag bei Testverein')
                ->assertSeeInHtml('Individuell konfiguriert für Testverein.')
                ->assertHasAttachedData(
                    $mail->applicationPdf,
                    'Mitgliedsantrag-'.$member->member_number.'.pdf',
                    ['mime' => 'application/pdf'],
                );
            $this->assertStringStartsWith('%PDF-', $mail->applicationPdf);
            $mail->assertSeeInHtml('separate E-Mail mit Hinweisen zur Anmeldung');

            return true;
        });
        // The immediately active member also receives the separate welcome mail.
        Mail::assertSent(MemberWelcomeMail::class, fn (MemberWelcomeMail $mail): bool => $mail->hasTo('applicant@example.com'));
    }

    public function test_selfservice_documents_use_club_logo_and_display_timezone(): void
    {
        $this->enable();
        Storage::fake('local');
        config(['app.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2026-09-27 00:30:00 UTC'));

        $logoPath = 'branding/logo-12345678-1234-1234-1234-123456789abc.png';
        Storage::disk('local')->put(
            $logoPath,
            UploadedFile::fake()->image('logo.png', 120, 60)->getContent(),
        );
        $settings = ClubSetting::current();
        $settings->update([
            'data' => [...$settings->data, 'logo_path' => $logoPath],
        ]);

        $documentData = [];
        View::composer('selfservice.document', function ($view) use (&$documentData): void {
            $documentData = $view->getData();
        });

        $this->post('/selfservice/zugang/bestaetigen', [
            'token' => $this->token('applicant@example.com', null, 'join'),
        ]);
        $this->post('/selfservice/formulare/application', $this->application())
            ->assertSessionHasNoErrors();

        $this->assertSame('27.09.2026 02:30:00 CEST', $documentData['timestamp']);
        $this->assertStringStartsWith('data:image/png;base64,', $documentData['logo']);
        $this->assertStringContainsString(
            '<img class="logo"',
            view('selfservice.document', $documentData)->render(),
        );
    }

    public function test_confirmation_links_keep_member_access_and_public_join_separate(): void
    {
        $this->enable();
        Mail::fake();
        $member = Member::factory()->create();

        $this->post('/selfservice/zugang/anfordern', [
            'email' => $member->email,
            'purpose' => 'login',
        ])->assertRedirect();
        $loginMail = Mail::sent(SelfServiceAccessMail::class)->first();
        $loginMailHtml = $loginMail->render();
        $this->assertStringContainsString(
            '/selfservice/zugang#token='.$loginMail->token,
            $loginMailHtml,
        );
        $this->assertStringContainsString('öffne den Mitgliederzugang', $loginMailHtml);

        $this->post('/selfservice/zugang/anfordern', [
            'email' => 'applicant@example.com',
            'purpose' => 'join',
        ])->assertRedirect();
        $joinMail = Mail::sent(SelfServiceAccessMail::class)->last();
        $joinMailHtml = $joinMail->render();
        $this->assertStringContainsString(
            '/selfservice/mitglied-werden#token='.$joinMail->token,
            $joinMailHtml,
        );
        $this->assertStringNotContainsString(
            '/selfservice/zugang#token='.$joinMail->token,
            $joinMailHtml,
        );
        $this->assertStringContainsString('öffne die Seite „Mitglied werden“', $joinMailHtml);
        $this->assertStringNotContainsString('öffne den Mitgliederzugang', $joinMailHtml);

        $this->get('/selfservice/mitglied-werden')->assertInertia(
            fn (Assert $page) => $page->component('selfservice/Join'),
        );
        $this->get('/selfservice/zugang')->assertInertia(
            fn (Assert $page) => $page->component('selfservice/Access'),
        );
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
        $values['iban'] = 'de89 3704 0044 0532 0130 00';
        // The club cannot collect without its own IBAN, so no mandate either.
        $this->post('/selfservice/formulare/sepa', $values)->assertSessionHasErrors(['accepted' => 'Der Verein muss zunächst folgende Angaben konfigurieren: Vereins-IBAN.']);
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'iban' => 'DE02120300000000202051']]);
        $values['version'] = $settings->fresh()->version;
        $this->post('/selfservice/formulare/sepa', $values)->assertSessionHasNoErrors()->assertRedirect('/selfservice');
        $this->assertSame('DE89370400440532013000', $member->fresh()->iban);
        $this->assertSame('SEPA-Lastschrift', $member->fresh()->payment_method);
        $this->assertNotNull($member->fresh()->mandate_reference);
        $this->assertDatabaseHas('member_documents', ['member_id' => $member->id, 'kind' => 'sepa', 'submitted_online' => true]);
        $firstReference = $member->fresh()->mandate_reference;
        $firstDocument = DB::table('member_documents')->where('member_id', $member->id)->where('kind', 'sepa')->sole();
        $this->get('/selfservice/mandat')->assertInertia(fn (Assert $page) => $page
            ->component('selfservice/Form')
            ->where('hasActiveMandate', true)
            ->where('member.iban', 'DE89370400440532013000'));

        $values['lock_version'] = $member->fresh()->lock_version;
        $values['iban'] = 'GB82 WEST 1234 5698 7654 32';
        $values['signature'] = $this->signature();
        $this->post('/selfservice/formulare/sepa', $values)->assertSessionHasNoErrors()->assertRedirect('/selfservice');
        $member->refresh();
        $this->assertSame('GB82WEST12345698765432', $member->iban);
        $this->assertNotSame($firstReference, $member->mandate_reference);
        $this->assertDatabaseCount('member_documents', 2);
        $this->assertNotNull(DB::table('member_documents')->where('id', $firstDocument->id)->value('revoked_at'));
        $this->assertDatabaseHas('member_documents', [
            'member_id' => $member->id,
            'kind' => 'sepa',
            'mandate_reference' => $member->mandate_reference,
            'revoked_at' => null,
        ]);
    }

    public function test_email_change_is_confirmed_directly_from_the_mail_link(): void
    {
        $this->enable();
        Mail::fake();
        $member = Member::factory()->create();
        $old = $member->email;
        $this->signIn($member)->post('/selfservice/zugang/anfordern', ['email' => 'new@example.com', 'purpose' => 'email'])->assertRedirect();
        $this->assertSame($old, $member->fresh()->email);
        $mail = Mail::sent(SelfServiceAccessMail::class)->first();
        $html = $mail->render();
        $this->assertStringContainsString(route('selfservice.email.confirm', ['token' => $mail->token]), $html);
        $this->assertStringContainsString('direkt bestätigt', $html);
        $this->assertStringNotContainsString('Bestätigungscode', $html);

        $this->postJson('/selfservice/zugang/bestaetigen', ['token' => $mail->token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');
        $this->assertSame($old, $member->fresh()->email);

        $this->flushSession();
        $this->get(route('selfservice.email.confirm', ['token' => $mail->token]))->assertRedirect('/selfservice');
        $this->assertSame('new@example.com', $member->fresh()->email);
        $this->assertDatabaseHas('member_changes', ['member_id' => $member->id, 'version' => 1]);
        $this->assertDatabaseMissing('selfservice_tokens', ['token_hash' => hash('sha256', $mail->token)]);
        $this->get('/selfservice')->assertOk();
        $this->get(route('selfservice.email.confirm', ['token' => $mail->token]))->assertGone();
    }

    public function test_configuration_is_admin_only_and_placeholders_are_validated(): void
    {
        $this->assertSame(
            'IBAN DE89 3704 0044 0532 0130 00',
            FormTemplates::renderText('IBAN {{verein.iban}}', ['iban' => 'DE89370400440532013000']),
        );
        $this->actingAs(User::factory()->create(['roles' => ['mv']]))->get('/konfiguration/selfservice')->assertForbidden();
        $this->actingAs(User::factory()->create(['roles' => ['admin']]));
        $this->get('/konfiguration/selfservice')->assertInertia(fn (Assert $page) => $page
            ->where('defaults.receipt_notes', FormTemplates::defaults()['receipt_notes'])
            ->where('defaults.receipt_donation_notes', FormTemplates::defaults()['receipt_donation_notes'])
            ->where('settings.receipt_notes', FormTemplates::defaults()['receipt_notes'])
            ->where('settings.receipt_donation_notes', FormTemplates::defaults()['receipt_donation_notes'])
            ->where('placeholders', fn ($placeholders): bool => $placeholders->contains('{{verein.tax_privilege_notice}}')));
        $values = ['version' => 0, 'selfservice_enabled' => true, 'public_join_enabled' => true, 'membership_activation' => 'immediate', ...FormTemplates::defaults(), 'welcome_mail_automatic' => true, 'email_filter_mode' => 'off', 'email_filter_patterns' => ''];
        $this->patchJson('/konfiguration/selfservice', [...$values, 'sepa_text' => '{{unknown}}'])->assertUnprocessable();
        $this->patch('/konfiguration/selfservice', $values)->assertSessionHasNoErrors();
        $this->assertTrue(ClubSetting::current()->data['selfservice_enabled']);
        $this->patchJson('/konfiguration/selfservice', $values)->assertUnprocessable()->assertJsonValidationErrors('version');
    }

    public function test_public_pages_and_mail_texts_are_configurable(): void
    {
        $this->get('/impressum')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Legal')
            ->where('title', 'Impressum'));
        $this->get('/datenschutz')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Legal')
            ->where('title', 'Datenschutzerklärung'));

        $this->actingAs(User::factory()->create(['roles' => ['admin']]));
        $this->get('/konfiguration/startseite')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('configuration/PublicPages')
            ->where('defaults.imprint_text', PublicPageTemplates::defaults()['imprint_text']));
        $this->assertStringContainsString('an den Vorstand', PublicPageTemplates::defaults()['contribution_invoice_mail_text']);
        $values = [...PublicPageTemplates::defaults(), 'version' => 0, 'imprint_text' => 'Impressum von {{verein.name}}'];
        $this->patch('/konfiguration/startseite', $values)->assertSessionHasNoErrors();
        $this->assertSame('Impressum von {{verein.name}}', ClubSetting::current()->data['imprint_text']);
    }

    public function test_application_offers_active_payment_methods_and_requires_complete_sepa_setup(): void
    {
        $this->enable();
        $this->post('/selfservice/zugang/bestaetigen', ['token' => $this->token('applicant@example.com', null, 'join')]);
        $this->get('/selfservice/beitritt')->assertInertia(fn (Assert $page) => $page
            ->where('paymentOptions.Überweisung', 'Überweisung')
            ->missing('paymentOptions.SEPA-Lastschrift'));

        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data,
            'account_holder' => 'Testverein', 'iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX',
        ]]);
        $payment = MemberFieldDefinition::query()->where('key', 'payment_method')->sole();
        $payment->update(['options' => collect($payment->options)->map(fn (array $option): array => $option['value'] === 'Bar' ? [...$option, 'active' => false] : $option)->all()]);

        $this->get('/selfservice/beitritt')->assertInertia(fn (Assert $page) => $page
            ->where('paymentOptions.SEPA-Lastschrift', 'SEPA-Lastschrift')
            ->missing('paymentOptions.Bar'));

        $values = [...$this->application(),
            'payment_method' => 'SEPA-Lastschrift',
            'mandate_accepted' => true,
            'mandate_signature' => $this->signature(),
            'iban' => 'DE89370400440532013000',
            'account_holder_first_name' => 'Ada',
            'account_holder_last_name' => 'Test',
            'account_holder_street' => 'Teststraße 1',
            'account_holder_postal_code' => '12345',
            'account_holder_city' => 'Teststadt',
            'account_holder_country' => 'DE',
        ];
        $this->post('/selfservice/formulare/application', $values)->assertSessionHasNoErrors()->assertRedirect('/selfservice');
        $member = Member::query()->sole();
        $this->assertSame('SEPA-Lastschrift', $member->payment_method);
        $this->assertNotNull($member->mandate_reference);
        $this->assertDatabaseHas('member_documents', ['member_id' => $member->id, 'kind' => 'application']);
        $this->assertDatabaseHas('member_documents', ['member_id' => $member->id, 'kind' => 'sepa']);
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

    public function test_email_confirmation_is_not_accepted_by_the_member_access_code_form(): void
    {
        $this->enable();
        $owner = Member::factory()->create();
        $other = Member::factory()->create();
        $token = $this->token('replacement@example.com', $owner, 'email');
        $this->signIn($other)
            ->postJson('/selfservice/zugang/bestaetigen', ['token' => $token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');
        $this->assertNotSame('replacement@example.com', $owner->fresh()->email);
        $this->assertNotSame('replacement@example.com', $other->fresh()->email);
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
        $this->actingAs($admin)->get('/mitglieder/antraege')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('totalMembers', 1)
            ->where('today', now()->toDateString())
            ->where('applications.total', 1));
        $joinedAt = now()->subYears(2)->toDateString();
        $this->post('/mitglieder/'.$member->member_number.'/beitritt-freigeben', [
            'lock_version' => $member->lock_version,
            'joined_at' => $joinedAt,
        ])->assertSessionHasNoErrors();
        $this->assertSame('Fördermitglied', $member->fresh()->membership_type);
        $this->assertSame($joinedAt, $member->fresh()->joined_at->toDateString());
        $this->assertDatabaseHas('membership_applications', ['member_id' => $member->id, 'approved_by' => $admin->id]);
        $this->assertDatabaseHas('member_changes', ['member_id' => $member->id, 'actor_id' => $admin->id, 'version' => $member->lock_version + 1]);
        $this->get('/selfservice/mandat')->assertOk();
        $this->assertSame($pdf, $this->get('/selfservice/dokumente/application')->getContent());
        $this->post('/mitglieder/'.$member->member_number.'/beitritt-freigeben', [
            'lock_version' => $member->fresh()->lock_version,
            'joined_at' => $joinedAt,
        ])->assertConflict();
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
        $this->postJson($url, [
            'lock_version' => $member->fresh()->lock_version,
        ])->assertUnprocessable()->assertJsonValidationErrors('joined_at');

        $futureJoinedAt = now()->addYear()->toDateString();
        $this->postJson($url, [
            'lock_version' => 0,
            'joined_at' => $futureJoinedAt,
        ])->assertUnprocessable()->assertJsonValidationErrors('lock_version');
        $this->assertNull($member->fresh()->joined_at);
        $this->assertDatabaseHas('membership_applications', ['member_id' => $member->id, 'approved_at' => null]);

        $this->post($url, [
            'lock_version' => $member->fresh()->lock_version,
            'joined_at' => $futureJoinedAt,
        ])->assertSessionHasNoErrors();
        $this->assertSame($futureJoinedAt, $member->fresh()->joined_at->toDateString());
    }

    public function test_activation_mode_is_configurable_and_rejects_unknown_values(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['admin']]));
        $this->get('/konfiguration/selfservice')->assertInertia(fn (Assert $page) => $page->where('settings.membership_activation', 'immediate'));
        $values = ['version' => 0, 'selfservice_enabled' => true, 'public_join_enabled' => true, 'membership_activation' => 'invalid', ...FormTemplates::defaults(), 'welcome_mail_automatic' => true, 'email_filter_mode' => 'off', 'email_filter_patterns' => ''];
        $this->patchJson('/konfiguration/selfservice', $values)->assertUnprocessable()->assertJsonValidationErrors('membership_activation');
        $this->patch('/konfiguration/selfservice', [...$values, 'membership_activation' => 'approval'])->assertSessionHasNoErrors();
        $this->assertSame('approval', ClubSetting::current()->data['membership_activation']);
    }
}
