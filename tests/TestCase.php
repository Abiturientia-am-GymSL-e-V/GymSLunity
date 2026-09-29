<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $database = $app['db']->connection()->getDatabaseName();

        // Check before RefreshDatabase can run migrations or delete any tables.
        if (! $app->environment('testing') || ! is_string($database)
            || ($database !== ':memory:' && ! str_ends_with($database, '_testing'))) {
            throw new RuntimeException('Tests require :memory: or a separate database ending in _testing. Configure .env.testing.');
        }

        return $app;
    }

    /**
     * A real login confirms the password (SecurityServiceProvider), so an
     * authenticated test user starts with a fresh confirmation as well.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        parent::actingAs($user, $guard);

        return $this->withSession(['auth.password_confirmed_at' => time()]);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
