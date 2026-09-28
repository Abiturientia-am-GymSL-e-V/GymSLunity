<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_privileged_user_must_configure_two_factor_after_login(): void
    {
        $user = User::factory()->create(['roles' => ['admin']]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->get(route('dashboard'))->assertRedirect(route('security.setup'));
        $this->get(route('security.setup'))->assertOk();
    }

    public function test_password_confirmation_submission_is_not_caught_in_a_redirect_loop(): void
    {
        $user = User::factory()->create(['roles' => ['admin']]);

        $this->actingAs($user)
            ->withSession([
                'security.authenticated_at' => now()->getTimestamp(),
                'url.intended' => route('security.setup'),
            ])
            ->post(route('password.confirm.store'), ['password' => 'password'])
            ->assertRedirect(route('security.setup'));
    }

    public function test_passkey_satisfies_privileged_second_factor_policy(): void
    {
        $user = User::factory()->create(['roles' => ['admin']]);
        $user->passkeys()->create([
            'name' => 'Testgerät',
            'credential_id' => 'test-credential',
            'credential' => ['id' => 'test-credential'],
        ]);

        $this->actingAs($user)
            ->withSession(['security.authenticated_at' => now()->getTimestamp()])
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_confirmed_two_factor_allows_privileged_access(): void
    {
        $user = User::factory()->withTwoFactor()->create(['roles' => ['admin']]);

        $this->actingAs($user)
            ->withSession(['security.authenticated_at' => now()->getTimestamp()])
            ->get(route('dashboard'))->assertOk();
    }

    public function test_inactive_session_is_terminated(): void
    {
        config(['security.inactivity_timeout' => 60]);
        $user = User::factory()->create(['roles' => []]);

        $this->actingAs($user)
            ->withSession(['security.last_activity' => now()->subSeconds(61)->getTimestamp()])
            ->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_security_headers_and_nonce_are_present(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("object-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertMatchesRegularExpression('/<script nonce="[^"]+">/', $response->getContent());
    }

    public function test_login_is_audited_without_plain_identifiers(): void
    {
        $user = User::factory()->create(['roles' => []]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $event = DB::table('security_audit_events')->where('event', 'login')->where('outcome', 'success')->sole();
        $this->assertSame($user->id, $event->user_id);
        $this->assertSame(64, strlen($event->ip_hash));
        $this->assertStringNotContainsString($user->email, (string) $event->context);
    }

    public function test_login_ip_limit_is_independent_from_account_limit(): void
    {
        RateLimiter::increment(md5('loginlogin-ip:127.0.0.1'), amount: 20);

        $this->post(route('login.store'), ['email' => 'fresh@example.invalid', 'password' => 'wrong'])
            ->assertTooManyRequests();
    }

    public function test_active_pdf_content_is_rejected(): void
    {
        $user = User::factory()->create(['roles' => ['mv']]);
        $member = Member::factory()->create();

        $this->actingAs($user)->post(route('members.documents.store', [
            'member' => $member->member_number,
            'kind' => 'application',
        ]), ['document' => UploadedFile::fake()->createWithContent('active.pdf', "%PDF-1.4\n/JavaScript (alert)\n%%EOF")])
            ->assertSessionHasErrors('document');
        $this->assertDatabaseCount('member_documents', 0);
    }

    public function test_security_check_fails_for_unsafe_production_configuration(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true, 'app.url' => 'http://example.invalid']);

        $this->artisan('security:check')->assertExitCode(1);
    }

    public function test_security_prune_applies_identifier_and_event_retention(): void
    {
        DB::table('security_audit_events')->insert([
            ['event' => 'old', 'outcome' => 'success', 'ip_hash' => str_repeat('a', 64), 'user_agent_hash' => str_repeat('b', 64), 'created_at' => now()->subDays(800)],
            ['event' => 'recent', 'outcome' => 'success', 'ip_hash' => str_repeat('c', 64), 'user_agent_hash' => str_repeat('d', 64), 'created_at' => now()->subDays(100)],
        ]);

        $this->artisan('security:prune')->assertSuccessful();

        $this->assertDatabaseMissing('security_audit_events', ['event' => 'old']);
        $this->assertDatabaseHas('security_audit_events', ['event' => 'recent', 'ip_hash' => null, 'user_agent_hash' => null]);
    }
}
