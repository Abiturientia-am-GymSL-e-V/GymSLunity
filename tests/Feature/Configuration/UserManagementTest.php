<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['roles' => ['admin']]);
        $this->actingAs($user);

        return $user;
    }

    private function data(array $overrides = []): array
    {
        return [...[
            'name' => 'Test Verwaltung', 'email' => 'test@example.invalid', 'password' => 'Long-test-password!42', 'password_confirmation' => 'Long-test-password!42',
            'roles' => ['mv'], 'is_active' => true, 'verified' => true,
        ], ...$overrides];
    }

    public function test_accounts_can_be_created_with_roles_without_exposing_passwords_or_secrets(): void
    {
        $this->admin();
        $this->post(route('configuration.users.store'), $this->data(['email' => 'TEST@example.invalid']))->assertSessionHasNoErrors();
        $target = User::query()->where('email', 'test@example.invalid')->sole();
        $this->assertSame(['mv'], $target->roles);
        $this->assertTrue($target->is_active);
        $this->assertNotNull($target->email_verified_at);
        $this->assertTrue(Hash::check('Long-test-password!42', $target->password));
        $audit = DB::table('configuration_changes')->sole();
        $this->assertStringNotContainsString('Long-test-password', $audit->after);
        $this->assertStringNotContainsString($target->password, $audit->after);
        $this->assertDatabaseHas('security_audit_events', [
            'event' => 'roles_changed', 'outcome' => 'success', 'user_id' => auth()->id(),
            'subject_type' => User::class, 'subject_id' => (string) $target->id,
        ]);
        $this->get(route('configuration.users.index', ['q' => 'TEST@']))->assertInertia(fn (Assert $page) => $page->where('users.total', 1)->where('users.data.0.roles', ['mv'])->missing('users.data.0.password')->missing('users.data.0.two_factor_secret')->missing('users.data.0.remember_token'));
    }

    public function test_user_management_exposes_the_areas_for_each_role(): void
    {
        $this->admin();

        $this->get(route('configuration.users.index'))->assertInertia(fn (Assert $page) => $page
            ->where('areas.admin', ['Mitglieder', 'Ämter, Abteilungen & Ehrungen', 'Beiträge', 'Auswertungen', 'Buchhaltung', 'Formulare', 'Spenden', 'Inventar', 'Kalender', 'Buchungen', 'Kommunikation', 'Auditlog', 'Konfiguration'])
            ->where('areas.vereinsverwaltung', ['Mitglieder', 'Ämter, Abteilungen & Ehrungen', 'Auswertungen', 'Formulare', 'Inventar', 'Kalender', 'Buchungen', 'Kommunikation'])
            ->where('areas.mv', ['Mitglieder', 'Ämter, Abteilungen & Ehrungen', 'Auswertungen', 'Formulare', 'Kalender', 'Buchungen', 'Kommunikation'])
            ->where('areas.auditor', ['Mitglieder (Lesen)', 'Ämter, Abteilungen & Ehrungen (Lesen)', 'Auswertungen', 'Auditlog'])
            ->where('areas.bh', ['Auswertungen', 'Buchhaltung', 'Spenden'])
            ->where('areas.bv', ['Beiträge', 'Auswertungen'])
            ->where('areas.kp', ['Auswertungen', 'Buchhaltung', 'Auditlog']));
    }

    public function test_role_validation_unique_email_and_password_rules_are_enforced(): void
    {
        $this->admin();
        $this->post(route('configuration.users.store'), $this->data(['roles' => ['root']]))->assertSessionHasErrors('roles.0');
        $this->post(route('configuration.users.store'), $this->data(['password' => 'short', 'password_confirmation' => 'short']))->assertSessionHasErrors('password');
        $this->post(route('configuration.users.store'), $this->data())->assertSessionHasNoErrors();
        $this->post(route('configuration.users.store'), $this->data(['email' => 'TEST@EXAMPLE.INVALID']))->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 2);
    }

    public function test_user_updates_detect_conflicts_and_invalidate_disabled_sessions(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['roles' => ['mv']]);
        DB::table('sessions')->insert(['id' => 'target-test-session', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);
        $this->patch(route('configuration.users.update', $target), $this->data(['email' => $target->email, 'lock_version' => 0, 'is_active' => false]))->assertSessionHasNoErrors();
        $this->assertFalse($target->fresh()->is_active);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-test-session']);
        $this->patch(route('configuration.users.update', $target), $this->data(['email' => $target->email, 'lock_version' => 0]))->assertSessionHasErrors('lock_version');
        foreach ([['roles' => ['mv']], ['is_active' => false], ['verified' => false]] as $change) {
            $this->patch(route('configuration.users.update', $admin), $this->data(['email' => $admin->email, 'roles' => ['admin'], 'lock_version' => 0, ...$change]))->assertSessionHasErrors('roles');
        }
        $this->assertTrue($admin->fresh()->isAdministrator());
    }

    public function test_disabled_accounts_cannot_log_in_or_continue_a_session(): void
    {
        $target = User::factory()->create(['is_active' => false, 'roles' => ['admin']]);
        $this->post(route('login'), ['email' => $target->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($target)->get(route('configuration.club.edit'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_disabling_an_account_also_blocks_a_pending_two_factor_challenge(): void
    {
        $target = User::factory()->withTwoFactor()->create(['roles' => ['admin']]);
        $codes = $target->recoveryCodes();
        $this->post(route('login'), ['email' => $target->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
        $target->forceFill(['is_active' => false])->save();
        $this->post(route('two-factor.login'), ['recovery_code' => $codes[0]])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_last_active_verified_administrator_cannot_delete_or_unverify_their_account(): void
    {
        $admin = $this->admin();
        User::factory()->create(['roles' => ['admin'], 'is_active' => false]);
        User::factory()->unverified()->create(['roles' => ['admin']]);
        $this->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasErrors('password');
        $this->patch(route('profile.update'), ['name' => $admin->name, 'email' => 'new@example.invalid'])->assertSessionHasErrors('email');
        $this->assertNotNull($admin->fresh()->email_verified_at);
        User::factory()->create(['roles' => ['admin']]);
        $this->patch(route('profile.update'), ['name' => $admin->name, 'email' => 'new@example.invalid'])->assertSessionHasNoErrors();
        $this->assertNull($admin->fresh()->email_verified_at);
    }
}
