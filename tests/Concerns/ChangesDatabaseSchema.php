<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

/**
 * For tests that change the schema (running migrations, dropping tables).
 * SQLite rolls such changes back with the test transaction of
 * RefreshDatabase; MariaDB/MySQL commit them implicitly. There the test runs
 * without a transaction on a freshly migrated database, and the next test
 * migrates again.
 */
trait ChangesDatabaseSchema
{
    use RefreshDatabase {
        refreshDatabase as refreshDatabaseInTransaction;
    }

    public function refreshDatabase(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->refreshDatabaseInTransaction();

            return;
        }

        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->app[Kernel::class]->setArtisan(null);
        RefreshDatabaseState::$migrated = false;
    }
}
