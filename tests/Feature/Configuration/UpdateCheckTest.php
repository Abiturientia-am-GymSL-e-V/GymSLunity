<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\System\UpdateCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UpdateCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        config(['app.update_check' => true, 'app.repository' => 'example/gymslunity']);
    }

    /** @param list<array{0: string, 1: bool}> $tags */
    private function releases(array $tags): void
    {
        Http::fake(['api.github.com/repos/example/gymslunity/releases*' => Http::response(array_map(fn (array $tag): array => [
            'tag_name' => $tag[0], 'prerelease' => $tag[1], 'draft' => false,
            'html_url' => 'https://github.com/example/gymslunity/releases/tag/'.$tag[0], 'published_at' => '2026-10-01T10:00:00Z',
        ], $tags))]);
    }

    public function test_a_newer_prerelease_is_offered_to_a_prerelease_installation(): void
    {
        $version = base_path('VERSION');
        $original = File::get($version);
        File::put($version, "0.1.0-beta.1\n");
        try {
            $this->releases([['v0.1.0-beta.2', true], ['v0.1.0-beta.10', true], ['v0.1.0-alpha.1', true]]);

            $status = app(UpdateCheck::class)->status();

            $this->assertSame('0.1.0-beta.1', $status['installed']);
            $this->assertSame('update', $status['status']);
            $this->assertSame('0.1.0-beta.10', $status['latest']['version']);
            $this->assertSame('https://github.com/example/gymslunity/releases/tag/v0.1.0-beta.10', $status['latest']['url']);
        } finally {
            File::put($version, $original);
        }
    }

    public function test_an_installed_latest_version_is_current_and_the_result_is_cached(): void
    {
        $this->releases([['v'.UpdateCheck::installedVersion(), true], ['v0.0.9', false]]);

        $this->assertSame('current', app(UpdateCheck::class)->status()['status']);
        $this->assertSame('current', app(UpdateCheck::class)->status()['status']);
        Http::assertSentCount(1);
    }

    public function test_a_stable_installation_ignores_prereleases(): void
    {
        $version = base_path('VERSION');
        $original = File::get($version);
        File::put($version, "1.0.0\n");
        try {
            $this->releases([['v1.1.0-beta.1', true], ['v1.0.1', false]]);
            $this->assertSame('1.0.1', app(UpdateCheck::class)->status()['latest']['version']);
        } finally {
            File::put($version, $original);
        }
    }

    public function test_an_unreachable_api_and_a_disabled_check_are_reported(): void
    {
        Http::fake(['api.github.com/*' => Http::response('rate limited', 403)]);
        $this->assertSame('unknown', app(UpdateCheck::class)->status()['status']);

        config(['app.update_check' => false]);
        $this->assertSame('disabled', app(UpdateCheck::class)->status()['status']);
    }

    public function test_the_system_page_loads_the_version_deferred_and_admins_can_check_again(): void
    {
        $this->releases([['v9.0.0', false]]);
        $this->actingAs(User::factory()->create(['roles' => ['admin']]));

        $this->get(route('configuration.system'))->assertInertia(fn (Assert $page) => $page
            ->missing('version')
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('version.latest.version', '9.0.0')));

        $this->post(route('configuration.system.updates'))->assertRedirect();
        $this->get(route('configuration.system'))->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('version.status', 'update')));
        Http::assertSentCount(2);

        $this->actingAs(User::factory()->create(['roles' => ['mv']]))
            ->post(route('configuration.system.updates'))->assertForbidden();
    }
}
