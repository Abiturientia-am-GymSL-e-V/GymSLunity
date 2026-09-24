<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SystemConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_administrators_can_view_system_configuration(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['mv']]))
            ->get(route('configuration.system'))
            ->assertForbidden();

        $this->actingAs(User::factory()->create(['roles' => ['admin']]))
            ->get(route('configuration.system'))
            ->assertOk();
    }

    public function test_system_configuration_exposes_effective_non_secret_settings(): void
    {
        config([
            'app.name' => 'GymSLunity',
            'app.url' => 'https://verein.example.test',
            'app.debug' => false,
            'cache.default' => 'redis',
            'session.driver' => 'database',
            'queue.default' => 'database',
            'database.redis.default.password' => 'must-not-be-rendered',
        ]);

        $this->actingAs(User::factory()->create(['roles' => ['admin']]))
            ->get(route('configuration.system'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('configuration/System')
                ->where('runtime.productName', 'GymSLunity')
                ->where('runtime.url', 'https://verein.example.test')
                ->where('runtime.cache', 'redis')
                ->where('redis.inUse', true)
                ->has('checks', 7)
                ->missing('redis.password')
                ->missing('runtime.appKey')
                ->missing('runtime.databasePassword'));
    }
}
