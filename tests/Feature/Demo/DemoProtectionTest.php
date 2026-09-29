<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Demo\DemoAccounts;
use App\Models\ClubSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DemoProtectionTest extends TestCase
{
    use RefreshDatabase;

    private const BLOCKED = 'In der öffentlichen Demo ist diese Funktion gesperrt.';

    private function demoAdmin(): User
    {
        return User::factory()->create(['email' => 'admin@example.org', 'roles' => ['admin']]);
    }

    public function test_shared_accounts_cannot_be_locked_or_taken_over(): void
    {
        config(['demo.enabled' => true]);
        $admin = $this->demoAdmin();
        $session = ['security.authenticated_at' => now()->getTimestamp(), 'auth.password_confirmed_at' => time()];

        $this->post(route('password.email'), ['email' => $admin->email])->assertInertiaFlash('toast.message', self::BLOCKED);
        $this->assertSame(0, DB::table('password_reset_tokens')->count());

        $this->actingAs($admin)->withSession($session)->from(route('profile.edit'))
            ->patch(route('profile.update'), ['name' => 'Übernommen', 'email' => 'fremd@example.org'])
            ->assertRedirect(route('profile.edit'))->assertInertiaFlash('toast.message', self::BLOCKED);
        $this->actingAs($admin)->withSession($session)
            ->put(route('user-password.update'), ['current_password' => 'password', 'password' => 'Neues-Passwort-123', 'password_confirmation' => 'Neues-Passwort-123'])
            ->assertInertiaFlash('toast.message', self::BLOCKED);
        $this->actingAs($admin)->withSession($session)->post(route('two-factor.enable'))->assertInertiaFlash('toast.message', self::BLOCKED);
        $this->actingAs($admin)->withSession($session)->getJson(route('passkey.registration-options'))->assertForbidden();
        $this->actingAs($admin)->withSession($session)->delete(route('profile.destroy'), ['password' => 'password'])->assertInertiaFlash('toast.message', self::BLOCKED);

        $admin->refresh();
        $this->assertSame('admin@example.org', $admin->email);
        $this->assertTrue(Hash::check('password', $admin->password));
        $this->assertNull($admin->two_factor_secret);
        $this->assertModelExists($admin);
    }

    public function test_demo_accounts_cannot_be_changed_in_user_management(): void
    {
        config(['demo.enabled' => true]);
        $admin = $this->demoAdmin();
        $vorstand = User::factory()->create(['email' => 'vorstand@example.org', 'roles' => ['vereinsverwaltung']]);

        $this->actingAs($admin)
            ->withSession(['security.authenticated_at' => now()->getTimestamp(), 'auth.password_confirmed_at' => time()])
            ->patch(route('configuration.users.update', $vorstand), ['name' => $vorstand->name, 'email' => $vorstand->email, 'roles' => [], 'is_active' => false, 'lock_version' => 0])
            ->assertInertiaFlash('toast.message', self::BLOCKED);

        $this->assertTrue($vorstand->refresh()->is_active);
        $this->assertSame(['vereinsverwaltung'], $vorstand->roles);
    }

    public function test_backups_and_public_club_content_are_locked(): void
    {
        config(['demo.enabled' => true]);
        $admin = $this->demoAdmin();
        $name = ClubSetting::current()->data['name'] ?? null;
        $session = ['security.authenticated_at' => now()->getTimestamp(), 'auth.password_confirmed_at' => time()];

        $this->actingAs($admin)->withSession($session)->get(route('configuration.backup.database.download'))->assertInertiaFlash('toast.message', self::BLOCKED);
        $this->actingAs($admin)->withSession($session)->get(route('configuration.backup.configuration.download'))->assertInertiaFlash('toast.message', self::BLOCKED);
        $this->actingAs($admin)->withSession($session)
            ->patch(route('configuration.club.update'), ['version' => 0, 'name' => 'Beliebiger Text', 'form_of_address' => 'du'])
            ->assertInertiaFlash('toast.message', self::BLOCKED);

        $this->assertSame($name, ClubSetting::current()->refresh()->data['name'] ?? null);
    }

    public function test_nothing_is_locked_outside_the_demo(): void
    {
        // Unprivileged, so the second-factor requirement does not interfere.
        $user = User::factory()->create(['email' => 'admin@example.org', 'roles' => []]);

        $this->post(route('password.email'), ['email' => $user->email]);
        $this->actingAs($user)
            ->withSession(['security.authenticated_at' => now()->getTimestamp(), 'auth.password_confirmed_at' => time()])
            ->patch(route('profile.update'), ['name' => 'Neuer Name', 'email' => 'admin@example.org'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Neuer Name', $user->refresh()->name);
        $this->assertSame(1, DB::table('password_reset_tokens')->count());
        $this->get(route('home'))->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_legal_pages_redirect_to_the_operator_in_the_demo(): void
    {
        config(['demo.enabled' => true, 'demo.imprint_url' => 'https://betreiber.example/impressum']);

        $this->get(route('imprint'))->assertRedirect('https://betreiber.example/impressum');
        $this->get(route('home'))->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get(route('privacy'))->assertOk();
    }

    public function test_shared_accounts_only_see_their_own_session(): void
    {
        config(['demo.enabled' => true, 'session.driver' => 'database']);
        $admin = $this->demoAdmin();
        DB::table('sessions')->insert(['id' => 'andere-sitzung', 'user_id' => $admin->id, 'ip_address' => '203.0.113.7', 'user_agent' => 'Firefox', 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($admin)
            ->withSession(['security.authenticated_at' => now()->getTimestamp(), 'auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('sessions', fn ($sessions) => collect($sessions)->doesntContain('ip_address', '203.0.113.7')));
    }

    public function test_documents_do_not_print_visitor_addresses(): void
    {
        config(['demo.enabled' => true]);

        $this->assertSame('nicht gespeichert (Demo)', DemoAccounts::documentIp('203.0.113.7'));
        config(['demo.enabled' => false]);
        $this->assertSame('203.0.113.7', DemoAccounts::documentIp('203.0.113.7'));
    }
}
