<?php

namespace Tests;

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

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
