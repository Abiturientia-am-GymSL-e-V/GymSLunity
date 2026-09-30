<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Backup\ApplicationBackup;
use App\Backup\BackupSignature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use Pdo\Mysql;
use ReflectionMethod;
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
        $manifest = [
            'format' => 'gymslunity-application-backup',
            'format_version' => 1,
            'backup_type' => 'database',
            'application_version' => trim((string) File::get(base_path('VERSION'))),
            'database_driver' => DB::getDriverName(),
            'checksums' => [$databaseFile => str_repeat('0', 64)],
        ];
        $manifest['signature'] = app(BackupSignature::class)->sign(json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
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

    public function test_restore_rejects_archives_not_signed_by_this_installation(): void
    {
        $directory = storage_path('framework/testing/backup-foreign-'.uniqid());
        File::ensureDirectoryExists($directory);
        config(['backup.path' => $directory]);
        $archive = $directory.'/incoming.zip';
        $databaseFile = DB::getDriverName() === 'sqlite' ? 'database.sqlite' : 'database.sql';
        $contents = "\\! id > /tmp/pwned\n";
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString($databaseFile, $contents);
        $zip->addFromString('manifest.json', json_encode([
            'format' => 'gymslunity-application-backup',
            'format_version' => 1,
            'backup_type' => 'database',
            'application_version' => trim((string) File::get(base_path('VERSION'))),
            'database_driver' => DB::getDriverName(),
            'checksums' => [$databaseFile => hash('sha256', $contents)],
            'signature' => str_repeat('a', 64),
        ], JSON_THROW_ON_ERROR));
        $zip->close();

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('nicht von dieser Installation');
            app(ApplicationBackup::class)->restoreDatabase($archive);
        } finally {
            $this->assertSame([], File::glob($directory.'/gymslunity-*.zip') ?: []);
            File::deleteDirectory($directory);
        }
    }

    public function test_mysql_client_options_use_the_certificate_authority_of_the_connection(): void
    {
        if (! defined(Mysql::class.'::ATTR_SSL_CA')) {
            $this->markTestSkipped('pdo_mysql ist nicht geladen.');
        }
        $directory = storage_path('framework/testing/client-options-'.uniqid());
        File::ensureDirectoryExists($directory);

        try {
            $method = new ReflectionMethod(ApplicationBackup::class, 'writeClientOptions');
            $file = $method->invoke(new ApplicationBackup, $directory, [
                'username' => 'verein',
                'password' => 'geheim "mit" Zeichen',
                'host' => 'db.example.test',
                'port' => '3307',
                'options' => [Mysql::ATTR_SSL_CA => '/etc/ssl/verein-db-ca.pem'],
            ]);

            $options = File::get($file);
            $this->assertStringContainsString('host="db.example.test"', $options);
            $this->assertStringContainsString('port="3307"', $options);
            $this->assertStringContainsString('password="geheim \\"mit\\" Zeichen"', $options);
            $this->assertStringContainsString('ssl-ca="/etc/ssl/verein-db-ca.pem"', $options);
            $this->assertSame(0600, fileperms($file) & 0777);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_mysql_client_options_without_certificate_authority_do_not_enable_tls(): void
    {
        $directory = storage_path('framework/testing/client-options-'.uniqid());
        File::ensureDirectoryExists($directory);

        try {
            $method = new ReflectionMethod(ApplicationBackup::class, 'writeClientOptions');
            $file = $method->invoke(new ApplicationBackup, $directory, ['username' => 'verein', 'host' => '127.0.0.1', 'options' => []]);

            $this->assertStringNotContainsString('ssl-ca', File::get($file));
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
