<?php

namespace Tests\Feature\Console;

use App\Backup\ApplicationBackup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class BackupApplicationTest extends TestCase
{
    public function test_database_backup_creates_a_private_restorable_archive_and_prunes_expired_files(): void
    {
        $directory = storage_path('framework/testing/backup-'.uniqid());
        File::ensureDirectoryExists($directory);
        $expired = $directory.'/gymslunity-20000101-000000-expired.zip';
        File::put($expired, 'expired');
        touch($expired, now()->subDays(60)->getTimestamp());
        config(['backup.path' => $directory, 'backup.retention_days' => 30]);

        try {
            $this->artisan('app:backup', ['--database-only' => true, '--prune' => true])
                ->assertSuccessful();

            $archives = array_values(array_filter(File::glob($directory.'/gymslunity-*.zip') ?: [], fn (string $file): bool => $file !== $expired));
            $this->assertCount(1, $archives);
            $this->assertFileDoesNotExist($expired);
            $this->assertSame(0600, fileperms($archives[0]) & 0777);

            $zip = new ZipArchive;
            $this->assertTrue($zip->open($archives[0]) === true);
            try {
                $entries = collect(range(0, $zip->numFiles - 1))->map(fn (int $index): string => (string) $zip->getNameIndex($index));
                $databaseFile = DB::getDriverName() === 'sqlite' ? 'database.sqlite' : 'database.sql';
                $this->assertNotFalse($zip->locateName($databaseFile), 'Archiveinträge: '.$entries->join(', '));
                $this->assertNotFalse($zip->locateName('manifest.json'));
                $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('gymslunity-application-backup', $manifest['format']);
                $this->assertSame(1, $manifest['format_version']);
                $this->assertSame('database', $manifest['backup_type']);
                $this->assertSame(DB::getDriverName(), $manifest['database_driver']);
                $this->assertSame([$databaseFile], $manifest['includes']);
                $this->assertSame(hash('sha256', (string) $zip->getFromName($databaseFile)), $manifest['checksums'][$databaseFile]);
            } finally {
                $zip->close();
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_sqlite_backup_contains_a_consistent_database_copy(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite ist in dieser Testumgebung nicht installiert.');
        }
        $directory = storage_path('framework/testing/backup-sqlite-'.uniqid());
        $originalConnection = DB::getDefaultConnection();
        config([
            'backup.path' => $directory,
            'database.connections.backup_sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::setDefaultConnection('backup_sqlite');

        try {
            DB::statement('CREATE TABLE backup_probe (value TEXT NOT NULL)');
            DB::table('backup_probe')->insert(['value' => 'gesichert']);
            $this->artisan('app:backup', ['--database-only' => true])->assertSuccessful();
            $archive = collect(File::glob($directory.'/gymslunity-*.zip') ?: [])->sole();
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($archive) === true);
            try {
                $database = $zip->getFromName('database.sqlite');
                $this->assertIsString($database);
                File::put($directory.'/restored.sqlite', $database);
            } finally {
                $zip->close();
            }
            $restored = new PDO('sqlite:'.$directory.'/restored.sqlite');
            $this->assertSame('gesichert', $restored->query('SELECT value FROM backup_probe')->fetchColumn());
        } finally {
            DB::purge('backup_sqlite');
            DB::setDefaultConnection($originalConnection);
            File::deleteDirectory($directory);
        }
    }

    public function test_restore_rejects_corrupt_archive_before_creating_safety_backup(): void
    {
        $directory = storage_path('framework/testing/backup-invalid-'.uniqid());
        File::ensureDirectoryExists($directory);
        config(['backup.path' => $directory]);
        $archive = $directory.'/incoming.zip';
        $databaseFile = DB::getDriverName() === 'sqlite' ? 'database.sqlite' : 'database.sql';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString($databaseFile, 'not a database');
        $zip->addFromString('manifest.json', json_encode([
            'format' => 'gymslunity-application-backup',
            'format_version' => 1,
            'backup_type' => 'database',
            'application_version' => trim((string) File::get(base_path('VERSION'))),
            'database_driver' => DB::getDriverName(),
            'checksums' => [$databaseFile => str_repeat('0', 64)],
        ], JSON_THROW_ON_ERROR));
        $zip->close();

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Prüfsumme');
            app(ApplicationBackup::class)->restoreDatabase($archive);
        } finally {
            $this->assertSame([], File::glob($directory.'/gymslunity-*.zip') ?: []);
            File::deleteDirectory($directory);
        }
    }
}
