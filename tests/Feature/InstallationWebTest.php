<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\ChangesDatabaseSchema;
use Tests\TestCase;

class InstallationWebTest extends TestCase
{
    use ChangesDatabaseSchema;

    public function test_browser_installer_form_is_available_before_first_user_exists(): void
    {
        $this->get(route('install.create'))
            ->assertOk()
            ->assertSee('Administratorkonto anlegen');
    }

    public function test_browser_installer_requires_the_setup_code_from_the_server(): void
    {
        $file = storage_path('app/setup-token');
        File::delete($file);

        try {
            $this->get(route('install.create'))->assertOk()->assertSee('Einrichtungscode');
            $this->assertFileExists($file);

            $this->post(route('install.store'), [
                'setup_token' => 'geraten',
                'name' => 'Angreifer',
                'email' => 'angreifer@example.org',
                'password' => 'Very-secret-123!',
                'password_confirmation' => 'Very-secret-123!',
                'accept' => '1',
            ])->assertSessionHasErrors('setup_token');
            $this->assertSame(0, User::query()->count());
        } finally {
            File::delete($file);
        }
    }

    public function test_pages_redirect_to_the_installer_until_the_database_is_migrated(): void
    {
        Schema::drop('club_settings');

        $this->get(route('home'))->assertRedirect(route('install.create'));
        $this->get(route('login'))->assertRedirect(route('install.create'));
    }

    public function test_pages_do_not_redirect_once_the_database_is_migrated(): void
    {
        $this->get(route('home'))->assertOk();
    }

    public function test_browser_installer_is_disabled_after_a_user_exists(): void
    {
        User::factory()->create();

        $this->get(route('install.create'))
            ->assertRedirect(route('login'));
    }
}
