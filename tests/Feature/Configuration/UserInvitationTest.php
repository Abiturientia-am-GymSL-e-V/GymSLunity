<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Mail\UserInvitationMail;
use App\Models\ClubSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class UserInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['roles' => ['admin']]);
        $this->actingAs($user);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function data(array $overrides = []): array
    {
        return [
            'name' => 'Grace Hopper', 'email' => 'grace@example.org', 'password' => '', 'password_confirmation' => '',
            'roles' => ['mv', 'bh'], 'is_active' => true, 'verified' => false, 'send_invitation' => true, ...$overrides,
        ];
    }

    private function formOfAddress(string $value): void
    {
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'name' => 'Testverein', 'form_of_address' => $value]]);
    }

    private function invitationUrl(): string
    {
        $url = null;
        Mail::assertSent(UserInvitationMail::class, function (UserInvitationMail $mail) use (&$url): bool {
            preg_match('#href="([^"]*/einladung/[^"]+)"#', $mail->render(), $matches);
            $url = html_entity_decode($matches[1] ?? '');

            return true;
        });
        $this->assertNotEmpty($url);

        return (string) $url;
    }

    public function test_new_account_receives_invitation_without_password_and_sets_its_own(): void
    {
        Mail::fake();
        $this->formOfAddress('du');
        $this->admin();

        $this->post(route('configuration.users.store'), $this->data())->assertSessionHasNoErrors();
        $user = User::query()->where('email', 'grace@example.org')->sole();
        Mail::assertSent(UserInvitationMail::class, function (UserInvitationMail $mail) use ($user): bool {
            $mail->assertTo('grace@example.org')
                ->assertHasSubject('Für dich wurde ein Benutzerkonto bei Testverein angelegt')
                ->assertSeeInHtml('Mitgliederverwaltung')
                ->assertSeeInHtml('Buchhaltung')
                ->assertSeeInHtml('Passwort festlegen')
                ->assertSeeInHtml('72 Stunden')
                ->assertSeeInHtml('Authenticator-App oder Passkey')
                ->assertDontSeeInHtml($user->password);

            return true;
        });
        $this->assertDatabaseHas('security_audit_events', ['event' => 'user_invitation_sent', 'outcome' => 'success', 'subject_id' => (string) $user->id]);

        $url = $this->invitationUrl();
        auth()->logout();
        $path = parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $token = basename((string) $path);
        $this->get($path.'?'.http_build_query($query))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('auth/AcceptInvitation')->where('email', 'grace@example.org')->where('token', $token));

        $this->post(route('invitation.store'), ['token' => $token, 'email' => 'grace@example.org', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
        $this->post(route('invitation.store'), ['token' => $token, 'email' => 'grace@example.org', 'password' => 'Long-test-password!42', 'password_confirmation' => 'Long-test-password!42'])
            ->assertSessionHasNoErrors()->assertRedirect(route('login'));
        $user->refresh();
        $this->assertTrue(Hash::check('Long-test-password!42', $user->password));
        $this->assertNotNull($user->email_verified_at, 'Redeeming the mailed link proves the address.');

        // The link works only once.
        $this->post(route('invitation.store'), ['token' => $token, 'email' => 'grace@example.org', 'password' => 'Other-test-password!42', 'password_confirmation' => 'Other-test-password!42'])
            ->assertSessionHasErrors('email');
    }

    public function test_formal_address_and_password_stays_required_without_invitation(): void
    {
        Mail::fake();
        $this->formOfAddress('sie');
        $this->admin();

        $this->post(route('configuration.users.store'), $this->data(['send_invitation' => false]))->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'grace@example.org']);
        $this->post(route('configuration.users.store'), $this->data([
            'send_invitation' => false, 'password' => 'Long-test-password!42', 'password_confirmation' => 'Long-test-password!42',
        ]))->assertSessionHasNoErrors();
        Mail::assertNothingSent();

        $this->post(route('configuration.users.store'), $this->data(['email' => 'ada@example.org']))->assertSessionHasNoErrors();
        Mail::assertSent(UserInvitationMail::class, fn (UserInvitationMail $mail): bool => $mail->hasTo('ada@example.org')
            && $mail->hasSubject('Für Sie wurde ein Benutzerkonto bei Testverein angelegt')
            && str_contains($mail->render(), 'Legen Sie zuerst'));
    }

    public function test_expired_or_revoked_links_and_inactive_accounts_are_rejected(): void
    {
        Mail::fake();
        $this->admin();
        $this->post(route('configuration.users.store'), $this->data())->assertSessionHasNoErrors();
        $user = User::query()->where('email', 'grace@example.org')->sole();
        $token = basename((string) parse_url($this->invitationUrl(), PHP_URL_PATH));
        $credentials = ['token' => $token, 'email' => 'grace@example.org', 'password' => 'Long-test-password!42', 'password_confirmation' => 'Long-test-password!42'];

        auth()->logout();
        $user->forceFill(['is_active' => false])->save();
        $this->post(route('invitation.store'), $credentials)->assertSessionHasErrors('email');
        $user->forceFill(['is_active' => true])->save();

        $this->travel(73)->hours();
        $this->post(route('invitation.store'), $credentials)->assertSessionHasErrors('email');
        $this->assertFalse(Hash::check('Long-test-password!42', $user->fresh()->password));
    }

    public function test_administrators_can_resend_and_the_old_link_stops_working(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $this->post(route('configuration.users.store'), $this->data())->assertSessionHasNoErrors();
        $user = User::query()->where('email', 'grace@example.org')->sole();
        $first = basename((string) parse_url($this->invitationUrl(), PHP_URL_PATH));

        $this->actingAs(User::factory()->create(['roles' => ['mv']]))->post(route('configuration.users.invite', $user))->assertForbidden();
        $this->actingAs($admin)->post(route('configuration.users.invite', $user))->assertSessionHasNoErrors();
        Mail::assertSent(UserInvitationMail::class, 2);
        Mail::assertSent(UserInvitationMail::class, fn (UserInvitationMail $mail): bool => $mail->resent && str_contains($mail->render(), 'Frühere Links funktionieren nicht mehr'));
        $this->assertSame(1, DB::table('user_invitation_tokens')->count());

        auth()->logout();
        $this->post(route('invitation.store'), ['token' => $first, 'email' => 'grace@example.org', 'password' => 'Long-test-password!42', 'password_confirmation' => 'Long-test-password!42'])
            ->assertSessionHasErrors('email');

        $this->actingAs($admin);
        $user->forceFill(['is_active' => false])->save();
        $this->post(route('configuration.users.invite', $user))->assertSessionHasErrors('invitation');
        Mail::assertSent(UserInvitationMail::class, 2);
    }

    public function test_failed_delivery_keeps_the_account_and_is_logged(): void
    {
        $this->admin();
        Event::listen(MessageSending::class, fn () => throw new RuntimeException('SMTP down'));

        $this->post(route('configuration.users.store'), $this->data())->assertSessionHasNoErrors()->assertRedirect(route('configuration.users.index'));
        $user = User::query()->where('email', 'grace@example.org')->sole();
        $this->assertSame(0, DB::table('user_invitation_tokens')->count());
        $this->assertDatabaseHas('security_audit_events', ['event' => 'user_invitation_sent', 'outcome' => 'failed', 'subject_id' => (string) $user->id]);
    }

    public function test_password_change_and_deactivation_revoke_open_invitations(): void
    {
        Mail::fake();
        $this->admin();
        $this->post(route('configuration.users.store'), $this->data())->assertSessionHasNoErrors();
        $user = User::query()->where('email', 'grace@example.org')->sole();
        $this->assertSame(1, DB::table('user_invitation_tokens')->count());

        $this->patch(route('configuration.users.update', $user), [...$this->data(), 'is_active' => false, 'lock_version' => $user->lock_version])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('user_invitation_tokens')->count());
    }
}
