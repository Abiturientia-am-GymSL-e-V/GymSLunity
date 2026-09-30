<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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

    public function test_user_can_be_created_without_questions(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'password-');
        file_put_contents($file, "Example-password-123\nignored second line\n");

        try {
            $this->artisan('app:create-user', [
                '--role' => ['admin'],
                '--name' => 'Scripted Admin',
                '--email' => 'Admin@Example.test',
                '--password-file' => $file,
            ])->assertSuccessful();
        } finally {
            unlink($file);
        }

        $user = User::sole();
        $this->assertSame('Scripted Admin', $user->name);
        $this->assertSame('admin@example.test', $user->email);
        $this->assertSame(['admin'], $user->roles);
        $this->assertTrue(Hash::check('Example-password-123', $user->password));
    }

    public function test_unreadable_password_file_does_not_create_a_user(): void
    {
        $this->artisan('app:create-user', [
            '--name' => 'Scripted Admin',
            '--email' => 'admin@example.test',
            '--password-file' => sys_get_temp_dir().'/does-not-exist-'.uniqid(),
        ])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_install_command_creates_the_administrator_without_questions_and_locks_the_installer(): void
    {
        $storage = sys_get_temp_dir().'/gymslunity-install-'.uniqid();
        mkdir($storage.'/app', 0700, true);
        $this->app->useStoragePath($storage);
        $file = $storage.'/password';
        file_put_contents($file, 'Example-password-123');

        try {
            $this->artisan('app:install', [
                '--force' => true,
                '--skip-migrations' => true,
                '--skip-storage-link' => true,
                '--admin-name' => 'Scripted Admin',
                '--admin-email' => 'admin@example.test',
                '--admin-password-file' => $file,
            ])->assertSuccessful();

            $this->assertSame(['admin'], User::sole()->roles);
            $this->assertFileExists($storage.'/app/installed');
        } finally {
            File::deleteDirectory($storage);
        }
    }

    public function test_install_command_can_run_safely_without_rotating_the_key_or_creating_a_user(): void
    {
        $key = config('app.key');

        $this->artisan('app:install', [
            '--force' => true,
            '--no-user' => true,
            '--skip-migrations' => true,
            '--skip-storage-link' => true,
        ])->assertSuccessful();

        $this->assertSame($key, config('app.key'));
        $this->assertDatabaseCount('users', 0);
    }
}
