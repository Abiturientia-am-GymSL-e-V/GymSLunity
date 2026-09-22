<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Uninvited User',
            'email' => 'uninvited@example.test',
            'password' => 'Example-password-123',
            'password_confirmation' => 'Example-password-123',
        ])->assertNotFound();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_user_can_be_created_with_the_console_command(): void
    {
        $this->artisan('app:create-user')
            ->expectsQuestion('Name', 'Development User')
            ->expectsQuestion('E-Mail-Adresse', 'Developer@Example.test')
            ->expectsQuestion('Passwort (mindestens 12 Zeichen)', 'Example-password-123')
            ->expectsQuestion('Passwort wiederholen', 'Example-password-123')
            ->assertSuccessful();

        $user = User::sole();
        $this->assertSame('developer@example.test', $user->email);
        $this->assertTrue(Hash::check('Example-password-123', $user->password));
        $this->assertTrue($user->hasVerifiedEmail());
    }

    public function test_invalid_password_does_not_create_a_user(): void
    {
        $this->artisan('app:create-user')
            ->expectsQuestion('Name', 'Development User')
            ->expectsQuestion('E-Mail-Adresse', 'developer@example.test')
            ->expectsQuestion('Passwort (mindestens 12 Zeichen)', 'short')
            ->expectsQuestion('Passwort wiederholen', 'different')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_member_role_can_be_granted_explicitly_from_the_console(): void
    {
        $this->artisan('app:create-user', ['--role' => ['mv']])
            ->expectsQuestion('Name', 'Membership Administrator')
            ->expectsQuestion('E-Mail-Adresse', 'membership@example.test')
            ->expectsQuestion('Passwort (mindestens 12 Zeichen)', 'Example-password-123')
            ->expectsQuestion('Passwort wiederholen', 'Example-password-123')
            ->assertSuccessful();

        $this->assertSame(['mv'], User::sole()->roles);
        $this->assertTrue(User::sole()->can('viewAny', Member::class));
    }

    public function test_unknown_console_roles_are_rejected(): void
    {
        $this->artisan('app:create-user', ['--role' => ['unknown']])
            ->expectsQuestion('Name', 'Invalid Role')
            ->expectsQuestion('E-Mail-Adresse', 'invalid@example.test')
            ->expectsQuestion('Passwort (mindestens 12 Zeichen)', 'Example-password-123')
            ->expectsQuestion('Passwort wiederholen', 'Example-password-123')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
