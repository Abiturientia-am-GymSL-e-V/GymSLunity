<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallationWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_installer_form_is_available_before_first_user_exists(): void
    {
        $this->get(route('install.create'))
            ->assertOk()
            ->assertSee('Administratorkonto anlegen');
    }

    public function test_browser_installer_is_disabled_after_a_user_exists(): void
    {
        User::factory()->create();

        $this->get(route('install.create'))
            ->assertRedirect(route('login'));
    }
}
