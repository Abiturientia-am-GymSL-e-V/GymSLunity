<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Demo\DemoAccounts;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DemoModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_mode_is_disabled_by_default(): void
    {
        $this->assertFalse(config('demo.enabled'));
        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('demo', null));
    }

    public function test_reset_refuses_to_delete_data_outside_the_demo(): void
    {
        $member = Member::factory()->create();

        $this->artisan('demo:reset')->assertFailed();

        $this->assertModelExists($member);
    }

    public function test_login_page_shows_the_demo_credentials(): void
    {
        config(['demo.enabled' => true, 'demo.password' => 'Geteiltes-Passwort']);

        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
            ->where('demo.password', 'Geteiltes-Passwort')
            ->where('demo.resetAt', '00:00')
            ->has('demo.accounts', count(DemoAccounts::USERS))
            ->where('demo.accounts.0', ['email' => 'admin@example.org', 'roles' => 'Administrator']));
    }

    public function test_shared_demo_accounts_do_not_need_a_second_factor(): void
    {
        config(['demo.enabled' => true]);
        $demoUser = User::factory()->create(['email' => 'admin@example.org', 'roles' => ['admin']]);
        $otherUser = User::factory()->create(['roles' => ['admin']]);

        $this->actingAs($demoUser)
            ->withSession(['security.authenticated_at' => now()->getTimestamp()])
            ->get(route('dashboard'))->assertOk();
        $this->actingAs($otherUser)
            ->withSession(['security.authenticated_at' => now()->getTimestamp()])
            ->get(route('dashboard'))->assertRedirect(route('security.setup'));
    }

    public function test_demo_accounts_need_a_second_factor_outside_the_demo(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.org', 'roles' => ['admin']]);

        $this->actingAs($user)
            ->withSession(['security.authenticated_at' => now()->getTimestamp()])
            ->get(route('dashboard'))->assertRedirect(route('security.setup'));
    }
}
