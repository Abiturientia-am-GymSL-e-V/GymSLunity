<?php

declare(strict_types=1);

namespace Tests\Feature\Members;

use App\Mail\MemberWelcomeMail;
use App\Models\ClubSetting;
use App\Models\CommunicationCampaign;
use App\Models\CommunicationDelivery;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class WelcomeMailTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $data */
    private function configure(array $data = []): void
    {
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'name' => 'Testverein', 'selfservice_enabled' => true, 'welcome_mail_automatic' => true, ...$data]]);
    }

    /** @param list<int> $numbers */
    private function send(array $numbers, bool $resend = false): TestResponse
    {
        return $this->post(route('members.welcome-mail'), ['members' => $numbers, 'resend' => $resend, 'request_id' => (string) Str::uuid()]);
    }

    /** @return list<string> */
    private function statuses(Member $member): array
    {
        return CommunicationDelivery::query()->where('member_id', $member->id)->orderBy('id')->pluck('status')->all();
    }

    private function member(array $attributes = []): Member
    {
        return Member::factory()->create(['joined_at' => '2020-01-01', 'left_at' => null, 'deceased_at' => null, ...$attributes]);
    }

    public function test_selected_members_receive_the_mail_once_and_skips_are_logged(): void
    {
        Mail::fake();
        $this->configure(['member_welcome_mail_subject' => 'Hallo {{mitglied.name}} bei {{verein.name}}']);
        $ada = $this->member(['first_name' => 'Ada', 'middle_name' => null, 'last_name' => 'Lovelace', 'email' => 'ada@example.org']);
        $noEmail = $this->member(['email' => null]);
        $this->actingAs(User::factory()->create(['roles' => ['mv']]));

        $this->send([$ada->member_number, $noEmail->member_number])->assertSessionHasNoErrors();
        Mail::assertSent(MemberWelcomeMail::class, 1);
        Mail::assertSent(MemberWelcomeMail::class, function (MemberWelcomeMail $mail): bool {
            $mail->assertTo('ada@example.org')
                ->assertHasSubject('Hallo Ada Lovelace bei Testverein')
                ->assertSeeInHtml('Mitgliederbereich öffnen')
                ->assertSeeInHtml('/selfservice/zugang')
                ->assertSeeInHtml('ada@example.org')
                ->assertDontSeeInHtml('nicht mehr zugelassen');

            return true;
        });
        $this->assertSame(['sent'], $this->statuses($ada));
        $this->assertSame(['skipped'], $this->statuses($noEmail));
        $campaign = CommunicationCampaign::query()->sole();
        $this->assertSame(['welcome', 1, 1, 0, 1], [$campaign->kind, $campaign->recipient_count, $campaign->success_count, $campaign->failure_count, $campaign->skipped_count]);

        // Without resend, members who already received the mail are skipped.
        $this->send([$ada->member_number])->assertSessionHasNoErrors();
        Mail::assertSent(MemberWelcomeMail::class, 1);
        $this->assertSame(['sent', 'skipped'], $this->statuses($ada));

        // A manual resend is possible at any time.
        $this->send([$ada->member_number], true)->assertSessionHasNoErrors();
        Mail::assertSent(MemberWelcomeMail::class, 2);
        $this->assertSame(['sent', 'skipped', 'sent'], $this->statuses($ada));
    }

    public function test_member_list_filters_by_welcome_mail_status_and_detail_shows_last_delivery(): void
    {
        Mail::fake();
        $this->configure();
        $received = $this->member(['email' => 'ada@example.org']);
        $missing = $this->member(['email' => 'bob@example.org']);
        $this->actingAs(User::factory()->create(['roles' => ['mv']]));
        $this->send([$received->member_number])->assertSessionHasNoErrors();

        $this->get(route('members.index', ['welcome' => 'missing']))->assertInertia(fn (Assert $page) => $page
            ->where('welcomeMailAvailable', true)
            ->where('filters.welcome', 'missing')
            ->where('members.total', 1)
            ->where('members.data.0.member_number', $missing->member_number));
        $this->get(route('members.index', ['welcome' => 'received']))->assertInertia(fn (Assert $page) => $page
            ->where('members.total', 1)
            ->where('members.data.0.member_number', $received->member_number));
        $this->get(route('members.index', ['welcome' => 'other']))->assertSessionHasErrors('welcome');

        $this->get(route('members.show', ['member' => $received->member_number]))->assertInertia(fn (Assert $page) => $page
            ->where('welcomeMail.available', true)
            ->where('welcomeMail.last_sent.recipient_email', 'ada@example.org'));
        $this->get(route('members.show', ['member' => $missing->member_number]))->assertInertia(fn (Assert $page) => $page
            ->where('welcomeMail.last_sent', null));
    }

    public function test_failed_delivery_is_not_marked_as_sent_and_can_be_retried(): void
    {
        $this->configure();
        $member = $this->member(['email' => 'ada@example.org']);
        $this->actingAs(User::factory()->create(['roles' => ['mv']]));
        $failing = true;
        Event::listen(MessageSending::class, function () use (&$failing): void {
            if ($failing) {
                throw new RuntimeException('SMTP password=secret rejected');
            }
        });

        $this->send([$member->member_number])->assertSessionHasNoErrors();
        $delivery = CommunicationDelivery::query()->sole();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame('Versand durch den Mailserver fehlgeschlagen.', $delivery->error);
        $this->assertSame(1, CommunicationCampaign::query()->sole()->failure_count);

        $this->get(route('members.index', ['welcome' => 'missing']))->assertInertia(fn (Assert $page) => $page->where('members.total', 1));
        $this->get(route('communication.history', ['campaign' => $delivery->campaign_id]))->assertInertia(fn (Assert $page) => $page
            ->where('deliveries.0.status', 'failed')
            ->where('deliveries.0.error', 'Versand durch den Mailserver fehlgeschlagen.'));

        $failing = false;
        $this->send([$member->member_number])->assertSessionHasNoErrors();
        $this->assertSame(['failed', 'sent'], $this->statuses($member));
    }

    public function test_repeated_request_and_running_delivery_do_not_send_twice(): void
    {
        Mail::fake();
        $this->configure();
        $member = $this->member(['email' => 'ada@example.org']);
        $this->actingAs(User::factory()->create(['roles' => ['mv']]));
        $payload = ['members' => [$member->member_number], 'resend' => true, 'request_id' => (string) Str::uuid()];

        $this->post(route('members.welcome-mail'), $payload)->assertSessionHasNoErrors();
        $this->post(route('members.welcome-mail'), $payload)->assertSessionHasNoErrors();
        Mail::assertSent(MemberWelcomeMail::class, 1);
        $this->assertSame(1, CommunicationCampaign::query()->count());

        // Another run is still delivering to this member.
        DB::table('communication_deliveries')->where('member_id', $member->id)->update(['status' => 'pending']);
        $this->send([$member->member_number], true)->assertSessionHasNoErrors();
        Mail::assertSent(MemberWelcomeMail::class, 1);
        $this->assertSame('Versand läuft bereits.', CommunicationDelivery::query()->latest('id')->value('error'));

        // An abandoned attempt no longer blocks.
        $this->travel(16)->minutes();
        $this->send([$member->member_number], true)->assertSessionHasNoErrors();
        Mail::assertSent(MemberWelcomeMail::class, 2);
    }

    public function test_sending_and_configuration_require_the_matching_permissions(): void
    {
        Mail::fake();
        $this->configure();
        $member = $this->member(['email' => 'ada@example.org']);

        foreach (['auditor', 'bh', 'bv', 'kp'] as $role) {
            $this->actingAs(User::factory()->create(['roles' => [$role]]));
            $this->send([$member->member_number])->assertForbidden();
        }
        Mail::assertNothingSent();

        $this->actingAs(User::factory()->create(['roles' => ['vereinsverwaltung']]));
        $this->send([$member->member_number])->assertSessionHasNoErrors();
        Mail::assertSent(MemberWelcomeMail::class, 1);
        $this->patch('/konfiguration/startseite', [])->assertForbidden();
        $this->patch('/konfiguration/selfservice', [])->assertForbidden();

        $this->configure(['selfservice_enabled' => false]);
        $this->send([$member->member_number], true)->assertSessionHasErrors('members');
        Mail::assertSent(MemberWelcomeMail::class, 1);
    }

    public function test_address_change_alert_depends_on_the_current_filter(): void
    {
        Mail::fake();
        $this->configure(['email_filter_mode' => 'block', 'email_filter_patterns' => '*@spam.test']);
        $blocked = $this->member(['email' => 'ada@spam.test']);
        $allowed = $this->member(['email' => 'bob@example.org']);
        $this->actingAs(User::factory()->create(['roles' => ['mv']]));

        $this->send([$blocked->member_number, $allowed->member_number])->assertSessionHasNoErrors();
        Mail::assertSent(MemberWelcomeMail::class, fn (MemberWelcomeMail $mail): bool => $mail->hasTo('ada@spam.test')
            && $mail->addressChangeRequired
            && str_contains($mail->render(), 'nicht mehr zugelassen')
            && str_contains($mail->render(), 'role="alert"'));
        Mail::assertSent(MemberWelcomeMail::class, fn (MemberWelcomeMail $mail): bool => $mail->hasTo('bob@example.org')
            && ! $mail->addressChangeRequired
            && ! str_contains($mail->render(), 'nicht mehr zugelassen'));
    }

    public function test_manual_creation_and_approval_send_automatically_unless_disabled(): void
    {
        Mail::fake();
        $this->configure();
        $this->actingAs(User::factory()->create(['roles' => ['mv']]));
        $store = fn (int $number, string $email) => $this->post(route('members.store'), [
            'member_number' => $number, 'configuration_version' => 0, 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'membership_type' => 'Fördermitglied', 'is_honorary' => false, 'email' => $email, 'joined_at' => '2024-01-01',
        ])->assertSessionHasNoErrors();

        $store(4711, 'ada@example.org');
        Mail::assertSent(MemberWelcomeMail::class, fn (MemberWelcomeMail $mail): bool => $mail->hasTo('ada@example.org'));
        $this->assertSame('Automatischer Versand', CommunicationCampaign::query()->sole()->created_by_name);

        $contact = Member::factory()->create(['email' => 'grace@example.org', 'membership_type' => 'Kontakt', 'joined_at' => null, 'left_at' => null, 'deceased_at' => null]);
        DB::table('membership_applications')->insert(['member_id' => $contact->id, 'membership_type' => 'Fördermitglied', 'submitted_at' => now()]);
        $contact->refresh();
        $this->post('/mitglieder/'.$contact->member_number.'/beitritt-freigeben', ['lock_version' => $contact->lock_version, 'joined_at' => now()->toDateString()])
            ->assertSessionHasNoErrors();
        Mail::assertSent(MemberWelcomeMail::class, fn (MemberWelcomeMail $mail): bool => $mail->hasTo('grace@example.org'));

        $this->configure(['welcome_mail_automatic' => false]);
        $store(4712, 'bob@example.org');
        Mail::assertNotSent(MemberWelcomeMail::class, fn (MemberWelcomeMail $mail): bool => $mail->hasTo('bob@example.org'));
        // Manual delivery still works while the automation is off.
        $this->send([4712])->assertSessionHasNoErrors();
        Mail::assertSent(MemberWelcomeMail::class, fn (MemberWelcomeMail $mail): bool => $mail->hasTo('bob@example.org'));
    }

    public function test_csv_import_and_members_without_membership_do_not_trigger_automatic_mails(): void
    {
        Mail::fake();
        $this->configure();
        $this->actingAs(User::factory()->create(['roles' => ['mv']]));

        $csv = "member_number;first_name;last_name;membership_type;is_honorary;email;joined_at\r\n8101;Grace;Hopper;Fördermitglied;nein;grace@example.org;2020-01-01";
        $preview = $this->post(route('members.import.preview'), ['csv' => UploadedFile::fake()->createWithContent('mitglieder.csv', $csv)])->assertSessionHasNoErrors();
        parse_str(parse_url($preview->headers->get('Location'), PHP_URL_QUERY) ?: '', $query);
        $this->post(route('members.import.store'), ['token' => $query['token']])->assertSessionHasNoErrors();
        $this->assertSame('grace@example.org', Member::query()->where('member_number', 8101)->sole()->email);

        $this->post(route('members.store'), [
            'member_number' => 4711, 'configuration_version' => 0, 'first_name' => 'Kim', 'last_name' => 'Ohne Eintritt',
            'membership_type' => 'Fördermitglied', 'is_honorary' => false, 'email' => 'kim@example.org',
        ])->assertSessionHasNoErrors();

        Mail::assertNothingSent();
    }

    public function test_welcome_text_accepts_member_placeholders_and_rejects_unknown_ones(): void
    {
        $this->configure();
        $this->actingAs(User::factory()->create(['roles' => ['admin']]));
        $this->get('/konfiguration/startseite')->assertInertia(fn (Assert $page) => $page
            ->where('settings.member_welcome_mail_subject', 'Willkommen im Mitgliederbereich von {{verein.name}}')
            ->where('memberPlaceholders', fn ($tokens): bool => $tokens->contains('{{mitglied.mitgliedsnummer}}') && ! $tokens->contains('{{verein.name}}')));
        $values = $this->get('/konfiguration/startseite')->viewData('page')['props']['settings'];
        $version = ClubSetting::current()->version;

        $this->patchJson('/konfiguration/startseite', [...$values, 'version' => $version, 'member_welcome_mail_text' => 'Hallo {{mitglied.unbekannt}}'])
            ->assertUnprocessable()->assertJsonValidationErrors('member_welcome_mail_text');
        // Member placeholders remain limited to the member-addressed mail.
        $this->patchJson('/konfiguration/startseite', [...$values, 'version' => $version, 'join_mail_text' => 'Hallo {{mitglied.name}}'])
            ->assertUnprocessable()->assertJsonValidationErrors('templates');
        $this->patch('/konfiguration/startseite', [...$values, 'version' => $version, 'member_welcome_mail_text' => 'Hallo {{mitglied.name}}, Nr. {{mitglied.mitgliedsnummer}} bei {{verein.name}}'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Hallo {{mitglied.name}}, Nr. {{mitglied.mitgliedsnummer}} bei {{verein.name}}', ClubSetting::current()->data['member_welcome_mail_text']);
    }
}
