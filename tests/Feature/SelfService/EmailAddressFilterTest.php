<?php

declare(strict_types=1);

namespace Tests\Feature\SelfService;

use App\Mail\SelfServiceAccessMail;
use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\User;
use App\SelfService\FormTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmailAddressFilterTest extends TestCase
{
    use RefreshDatabase;

    private function configure(string $mode, string $patterns = ''): void
    {
        $settings = ClubSetting::current();
        $settings->update(['data' => [
            ...$settings->data,
            'selfservice_enabled' => true, 'public_join_enabled' => true, 'name' => 'Testverein',
            'email_filter_mode' => $mode, 'email_filter_patterns' => $patterns,
        ]]);
    }

    private function signIn(Member $member): self
    {
        return $this->withSession(['selfservice' => ['email' => strtolower($member->email), 'member_id' => $member->id, 'until' => time() + 1800]]);
    }

    private function token(string $email, ?Member $member, string $purpose): string
    {
        $token = bin2hex(random_bytes(32));
        DB::table('selfservice_tokens')->insert(['token_hash' => hash('sha256', $token), 'member_id' => $member?->id, 'email' => $email, 'purpose' => $purpose, 'expires_at' => now()->addMinutes(15)]);

        return $token;
    }

    /** @return array<string, mixed> */
    private function settingsPayload(array $overrides = []): array
    {
        return [
            'version' => ClubSetting::current()->version, 'selfservice_enabled' => true, 'public_join_enabled' => true,
            'membership_activation' => 'immediate', ...FormTemplates::defaults(),
            'email_filter_mode' => 'off', 'email_filter_patterns' => '', ...$overrides,
        ];
    }

    public function test_only_administrators_configure_the_filter_and_patterns_are_validated_and_normalized(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['mv']]))
            ->patch('/konfiguration/selfservice', $this->settingsPayload())->assertForbidden();

        $this->actingAs(User::factory()->create(['roles' => ['admin']]));
        $this->get('/konfiguration/selfservice')->assertInertia(fn (Assert $page) => $page
            ->where('settings.email_filter_mode', 'off')->where('settings.email_filter_patterns', ''));

        $this->patchJson('/konfiguration/selfservice', $this->settingsPayload(['email_filter_mode' => 'maybe']))
            ->assertUnprocessable()->assertJsonValidationErrors('email_filter_mode');
        $this->patchJson('/konfiguration/selfservice', $this->settingsPayload(['email_filter_mode' => 'block', 'email_filter_patterns' => "*@ok.de\ngymsl.de\n\na@b@c"]))
            ->assertUnprocessable()->assertJsonValidationErrors(['email_filter_patterns.2', 'email_filter_patterns.4']);
        $this->patchJson('/konfiguration/selfservice', $this->settingsPayload(['email_filter_mode' => 'allow', 'email_filter_patterns' => "\n  \n"]))
            ->assertUnprocessable()->assertJsonValidationErrors('email_filter_patterns');

        $this->patch('/konfiguration/selfservice', $this->settingsPayload(['email_filter_mode' => 'allow', 'email_filter_patterns' => " *@GymSL.de \r\n\r\n*@*.gymsl.de\n*@gymsl.de"]))
            ->assertSessionHasNoErrors();
        $data = ClubSetting::current()->data;
        $this->assertSame('allow', $data['email_filter_mode']);
        $this->assertSame("*@gymsl.de\n*@*.gymsl.de", $data['email_filter_patterns']);
    }

    public function test_public_join_respects_allowlist_blocklist_and_disabled_mode(): void
    {
        Mail::fake();
        $this->configure('allow', "*@gymsl.de\n*@*.gymsl.de");
        $this->post('/selfservice/zugang/anfordern', ['email' => 'ada@other.de', 'purpose' => 'join'])->assertSessionHasErrors('email');
        $this->post('/selfservice/zugang/anfordern', ['email' => 'Ada@GymSL.de', 'purpose' => 'join'])->assertSessionHasNoErrors();
        $this->post('/selfservice/zugang/anfordern', ['email' => 'bob@mail.gymsl.de', 'purpose' => 'join'])->assertSessionHasNoErrors();
        $this->assertSame(['ada@gymsl.de', 'bob@mail.gymsl.de'], DB::table('selfservice_tokens')->orderBy('id')->pluck('email')->all());

        $this->configure('block', '*@spam.test');
        $this->post('/selfservice/zugang/anfordern', ['email' => 'x@SPAM.test', 'purpose' => 'join'])->assertSessionHasErrors('email');
        $this->post('/selfservice/zugang/anfordern', ['email' => 'x@other.de', 'purpose' => 'join'])->assertSessionHasNoErrors();

        $this->configure('off', '*@spam.test');
        $this->post('/selfservice/zugang/anfordern', ['email' => 'y@spam.test', 'purpose' => 'join'])->assertSessionHasNoErrors();
        $this->assertSame(4, DB::table('selfservice_tokens')->count());
    }

    public function test_join_token_is_rechecked_when_the_filter_changed_meanwhile(): void
    {
        $this->configure('off');
        $token = $this->token('ada@spam.test', null, 'join');
        $this->configure('block', '*@spam.test');

        $this->post('/selfservice/zugang/bestaetigen', ['token' => $token])->assertSessionHasErrors('token');
        $this->assertNull(session('selfservice'));
    }

    public function test_login_links_are_still_sent_to_filtered_addresses(): void
    {
        Mail::fake();
        $this->configure('block', '*@spam.test');
        Member::factory()->create(['email' => 'ada@spam.test']);

        $this->post('/selfservice/zugang/anfordern', ['email' => 'ada@spam.test', 'purpose' => 'login'])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('selfservice_tokens')->where('purpose', 'login')->count());
    }

    public function test_email_change_and_its_confirmation_respect_the_filter(): void
    {
        Mail::fake();
        $this->configure('block', '*@spam.test');
        $member = Member::factory()->create(['email' => 'ada@example.org']);

        $this->signIn($member)->post('/selfservice/zugang/anfordern', ['email' => 'ada@spam.test', 'purpose' => 'email'])->assertSessionHasErrors('email');
        Mail::assertNothingSent();

        $this->configure('off');
        $token = $this->token('ada@spam.test', $member, 'email');
        $this->configure('block', '*@spam.test');
        $this->get('/selfservice/email/bestaetigen/'.$token)->assertStatus(409);
        $this->assertSame('ada@example.org', $member->fresh()->email);
    }

    public function test_filtered_member_is_limited_to_changing_the_address_until_the_new_one_is_confirmed(): void
    {
        Mail::fake();
        $this->configure('block', '*@spam.test');
        $member = Member::factory()->create(['email' => 'ada@spam.test', 'first_name' => 'Ada']);

        $this->signIn($member)->get('/selfservice')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('selfservice/EmailChangeRequired')
            ->where('member.email', 'ada@spam.test')
            ->where('pendingEmail', null)
            ->missing('profileSections'));
        $this->patch('/selfservice/profil', ['lock_version' => $member->lock_version, 'first_name' => 'Eve'])->assertRedirect('/selfservice');
        $this->assertSame('Ada', $member->fresh()->first_name);
        $this->get('/selfservice/dokumente/application')->assertRedirect('/selfservice');
        $this->get('/selfservice/beitritt')->assertRedirect('/selfservice');
        $this->patch('/selfservice/mitgliedschaft/kuendigen', ['lock_version' => $member->lock_version])->assertRedirect('/selfservice');
        $this->getJson('/selfservice/passkeys/optionen')->assertStatus(423);

        $this->post('/selfservice/zugang/anfordern', ['email' => 'ada@example.org', 'purpose' => 'email'])->assertSessionHasNoErrors();
        Mail::assertSent(SelfServiceAccessMail::class, fn (SelfServiceAccessMail $mail): bool => $mail->hasTo('ada@example.org'));
        $this->get('/selfservice')->assertInertia(fn (Assert $page) => $page
            ->component('selfservice/EmailChangeRequired')->where('pendingEmail', 'a***@example.org'));
        $this->assertSame('ada@spam.test', $member->fresh()->email, 'The old address stays valid until confirmation.');

        $token = $this->token('ada@example.org', $member, 'email');
        $this->get('/selfservice/email/bestaetigen/'.$token)->assertRedirect('/selfservice');
        $this->assertSame('ada@example.org', $member->fresh()->email);
        $this->get('/selfservice')->assertInertia(fn (Assert $page) => $page->component('selfservice/Portal'));
    }

    public function test_filter_changes_apply_to_existing_accounts_without_changing_their_addresses(): void
    {
        $this->configure('off');
        $member = Member::factory()->create(['email' => 'ada@spam.test']);
        $this->signIn($member)->get('/selfservice')->assertInertia(fn (Assert $page) => $page->component('selfservice/Portal'));

        $this->configure('allow', '*@gymsl.de');
        $this->get('/selfservice')->assertInertia(fn (Assert $page) => $page->component('selfservice/EmailChangeRequired'));

        $this->configure('allow', "*@gymsl.de\n*@spam.test");
        $this->get('/selfservice')->assertInertia(fn (Assert $page) => $page->component('selfservice/Portal'));
        $this->assertSame('ada@spam.test', $member->fresh()->email);
    }

    public function test_administration_may_store_filtered_addresses_and_sees_a_hint(): void
    {
        $this->configure('block', '*@spam.test');
        $this->actingAs(User::factory()->create(['roles' => ['mv']]));

        $this->post(route('members.store'), [
            'member_number' => 4711, 'configuration_version' => 0, 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'membership_type' => 'Fördermitglied', 'is_honorary' => false, 'email' => 'ada@spam.test',
        ])->assertSessionHasNoErrors();
        $this->get(route('members.show', ['member' => 4711]))->assertInertia(fn (Assert $page) => $page->where('emailFilterViolation', true));

        $csv = "member_number;first_name;last_name;membership_type;is_honorary;email\r\n8101;Grace;Hopper;Fördermitglied;nein;grace@spam.test";
        $preview = $this->post(route('members.import.preview'), ['csv' => UploadedFile::fake()->createWithContent('mitglieder.csv', $csv)])
            ->assertSessionHasNoErrors();
        parse_str(parse_url($preview->headers->get('Location'), PHP_URL_QUERY) ?: '', $query);
        $this->post(route('members.import.store'), ['token' => $query['token']])->assertSessionHasNoErrors();
        $this->assertSame('grace@spam.test', Member::query()->where('member_number', 8101)->sole()->email);
    }
}
