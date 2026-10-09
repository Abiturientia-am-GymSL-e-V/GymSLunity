<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RedirectRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_redirects_to_the_profile(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/settings')
            ->assertRedirect('/settings/profile');
    }

    public function test_configuration_redirects_to_the_club_settings(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['admin']]))
            ->get('/konfiguration')
            ->assertRedirect('/konfiguration/verein');
    }

    public function test_redirects_only_answer_get_requests(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['admin']]))
            ->post('/konfiguration')
            ->assertStatus(405);
    }
}
