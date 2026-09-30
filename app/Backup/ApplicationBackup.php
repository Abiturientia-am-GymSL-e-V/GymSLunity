<?php

declare(strict_types=1);

namespace App\Backup;

use App\Support\Clock;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Pdo\Mysql;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

class ApplicationBackup
{
    private const MANIFEST_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public function __construct(private readonly BackupSignature $signature = new BackupSignature) {}

    public function create(bool $databaseOnly = false, ?string $destinationDirectory = null): string
    {
        $root = $destinationDirectory ?? $this->backupRoot();
        $temporary = $root.'/.tmp-'.Str::uuid();
        $filename = 'gymslunity-'.Clock::localNow()->format('Ymd-His').'-'.Str::lower(Str::random(8)).'.zip';
        $archive = $root.'/'.$filename;
        $partial = $archive.'.partial';

        try {
            $rootAlreadyExists = is_dir($root);
            File::ensureDirectoryExists($root, 0700, true);
            if (! $rootAlreadyExists) {
                @chmod($root, 0700);
            }
            $this->assertOutsideApplicationFiles($root, $databaseOnly);
            File::ensureDirectoryExists($temporary, 0700, true);
            $databaseFile = $this->dumpDatabase($temporary);

            $includes = [basename($databaseFile)];
            if (! $databaseOnly) {
                $environment = base_path('.env');
                if (! is_file($environment) || ! File::copy($environment, $temporary.'/environment.env')) {
                    throw new RuntimeException('Die Datei .env konnte nicht gesichert werden.');
                }
                $includes[] = 'environment.env';
                $files = storage_path('app');
                if (is_dir($files)) {
                    $this->copyDirectoryWithoutLinks($files, $temporary.'/storage-app');
                }
                File::ensureDirectoryExists($temporary.'/storage-app');
                $includes[] = 'storage-app/';
            }

            $databaseChecksum = hash_file('sha256', $databaseFile);
            if (! is_string($databaseChecksum)) {
                throw new RuntimeException('Für den Datenbankexport konnte keine Prüfsumme erstellt werden.');
            }
            $manifest = [
                'format' => 'gymslunity-application-backup',
                'format_version' => 1,
                'backup_type' => $databaseOnly ? 'database' : 'full',
                'created_at' => now()->toIso8601String(),
                'application_version' => $this->applicationVersion(),
                'database_connection' => DB::getDefaultConnection(),
                'database_driver' => DB::getDriverName(),
                'includes' => $includes,
                'checksums' => [basename($databaseFile) => $databaseChecksum],
            ];
            $manifest['signature'] = $this->signature->sign(json_encode($manifest, self::MANIFEST_FLAGS));
            File::put($temporary.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | self::MANIFEST_FLAGS).PHP_EOL);
            if (! touch($partial)) {
                throw new RuntimeException('Die temporäre Backup-Datei konnte nicht angelegt werden.');
            }
            @chmod($partial, 0600);
            $this->createArchive($temporary, $partial);
            if (! File::move($partial, $archive)) {
                throw new RuntimeException('Das fertige Backup-Archiv konnte nicht verschoben werden.');
            }
            @chmod($archive, 0600);

            return $archive;
        } finally {
            File::deleteDirectory($temporary);
            File::delete($partial);
        }
    }

    public function prune(string $current): int
    {
        $root = dirname($current);
        $cutoff = now()->subDays((int) config('backup.retention_days', 30))->getTimestamp();
        $deleted = 0;
        foreach (File::glob($root.'/gymslunity-*.zip') ?: [] as $file) {
            if ($file !== $current && is_file($file) && filemtime($file) < $cutoff && File::delete($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Restores a database archive and returns the path of the safety backup
     * created immediately before the restore.
     */
    public function restoreDatabase(string $archive): string
    {
        $temporary = storage_path('framework/cache/backup-restore-'.Str::uuid());

        try {
            File::ensureDirectoryExists($temporary, 0700, true);
            $databaseFile = $this->databaseFileFromArchive($archive, $temporary.'/incoming');
            $safetyBackup = $this->create(databaseOnly: true);

            try {
                $this->importDatabase($databaseFile);
            } catch (Throwable $restoreException) {
                try {
                    $safetyFile = $this->databaseFileFromArchive($safetyBackup, $temporary.'/safety');
                    $this->importDatabase($safetyFile);
                } catch (Throwable) {
                    throw new RuntimeException(
                        'Die Wiederherstellung ist fehlgeschlagen und auch die automatische Rückkehr zur Sicherheitssicherung war nicht möglich. Sicherheitssicherung: '.$safetyBackup,
                        previous: $restoreException,
                    );
                }

                throw new RuntimeException(
                    'Die Wiederherstellung ist fehlgeschlagen. Der vorherige Datenbankstand wurde automatisch wiederhergestellt.',
                    previous: $restoreException,
                );
            }

            return $safetyBackup;
        } finally {
            File::deleteDirectory($temporary);
        }
    }

    private function backupRoot(): string
    {
        $configured = config('backup.path');
        if (! is_string($configured) || trim($configured) === '') {
            throw new RuntimeException('BACKUP_PATH ist nicht konfiguriert.');
        }

        return str_starts_with($configured, DIRECTORY_SEPARATOR)
            ? rtrim($configured, DIRECTORY_SEPARATOR)
            : base_path(rtrim($configured, DIRECTORY_SEPARATOR));
    }

    private function assertOutsideApplicationFiles(string $root, bool $databaseOnly): void
    {
        if ($databaseOnly) {
            return;
        }
        $backup = realpath($root);
        $files = realpath(storage_path('app'));
        if (is_string($backup) && is_string($files)
            && ($backup === $files || str_starts_with($backup.DIRECTORY_SEPARATOR, $files.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('BACKUP_PATH darf nicht innerhalb von storage/app liegen.');
        }
    }

    private function dumpDatabase(string $directory): string
    {
        return match (DB::getDriverName()) {
            'sqlite' => $this->dumpSqlite($directory),
            'mysql', 'mariadb' => $this->dumpMysql($directory),
            default => throw new RuntimeException('Der konfigurierte Datenbanktreiber wird für Backups nicht unterstützt.'),
        };
    }

    private function dumpSqlite(string $directory): string
    {
        $target = $directory.'/database.sqlite';
        $pdo = DB::connection()->getPdo();
        $quoted = $pdo->quote($target);
        if (! is_string($quoted) || $pdo->exec('VACUUM INTO '.$quoted) === false || ! is_file($target)) {
            throw new RuntimeException('Die SQLite-Datenbank konnte nicht konsistent exportiert werden.');
        }

        return $target;
    }

    private function dumpMysql(string $directory): string
    {
        $binary = (new ExecutableFinder)->find('mariadb-dump')
            ?? (new ExecutableFinder)->find('mysqldump');
        if ($binary === null) {
            throw new RuntimeException('Für das Backup wird mariadb-dump oder mysqldump benötigt.');
        }
        $connection = $this->mysqlConnection();
        $credentials = $this->writeClientOptions($directory, $connection);
        $target = $directory.'/database.sql';
        try {
            $process = new Process([
                $binary,
                '--defaults-extra-file='.$credentials,
                '--single-transaction',
                '--quick',
                '--skip-lock-tables',
                '--hex-blob',
                '--default-character-set=utf8mb4',
                '--result-file='.$target,
                $connection['database'],
            ]);
            $process->setTimeout(600)->run();
            if (! $process->isSuccessful() || ! is_file($target)) {
                throw new RuntimeException('Der Datenbankexport ist fehlgeschlagen: '.trim($process->getErrorOutput()));
            }
        } finally {
            File::delete($credentials);
        }

        return $target;
    }

    /** @return array<string, mixed> */
    private function mysqlConnection(): array
    {
        $connection = config('database.connections.'.DB::getDefaultConnection());
        if (! is_array($connection) || ! is_string($connection['database'] ?? null) || $connection['database'] === '') {
            throw new RuntimeException('Die MySQL-/MariaDB-Verbindung ist unvollständig konfiguriert.');
        }

        return $connection;
    }

    /** @param array<string, mixed> $connection */
    private function writeClientOptions(string $directory, array $connection): string
    {
        $credentials = $directory.'/.database-client.cnf';
        $options = ['[client]'];
        foreach (['username' => 'user', 'password' => 'password', 'host' => 'host', 'port' => 'port', 'unix_socket' => 'socket'] as $key => $option) {
            $value = $connection[$key] ?? null;
            if ($value !== null && $value !== '') {
                $options[] = $option.'='.$this->optionFileValue((string) $value);
            }
        }
        // Same TLS trust as the application connection (MYSQL_ATTR_SSL_CA).
        $certificateAuthority = defined(Mysql::class.'::ATTR_SSL_CA')
            ? ($connection['options'][Mysql::ATTR_SSL_CA] ?? null)
            : null;
        if (is_string($certificateAuthority) && $certificateAuthority !== '') {
            $options[] = 'ssl-ca='.$this->optionFileValue($certificateAuthority);
        }
        File::put($credentials, implode(PHP_EOL, $options).PHP_EOL);
        @chmod($credentials, 0600);

        return $credentials;
    }

    private function importDatabase(string $databaseFile): void
    {
        match (DB::getDriverName()) {
            'sqlite' => $this->importSqlite($databaseFile),
            'mysql', 'mariadb' => $this->importMysql($databaseFile),
            default => throw new RuntimeException('Der konfigurierte Datenbanktreiber wird für Wiederherstellungen nicht unterstützt.'),
        };
    }

    private function importMysql(string $databaseFile): void
    {
        $binary = (new ExecutableFinder)->find('mariadb')
            ?? (new ExecutableFinder)->find('mysql');
        if ($binary === null) {
            throw new RuntimeException('Für die Wiederherstellung wird mariadb oder mysql benötigt.');
        }
        $connection = $this->mysqlConnection();
        $credentials = $this->writeClientOptions(dirname($databaseFile), $connection);
        $input = fopen($databaseFile, 'rb');
        if ($input === false) {
            File::delete($credentials);
            throw new RuntimeException('Der Datenbankexport konnte nicht gelesen werden.');
        }
        try {
            $process = new Process([
                $binary,
                '--defaults-extra-file='.$credentials,
                '--default-character-set=utf8mb4',
                ...($this->supportsSandbox($binary) ? ['--sandbox'] : []),
                $connection['database'],
            ]);
            $process->setInput($input)->setTimeout(1200)->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Der Datenbankimport ist fehlgeschlagen: '.trim($process->getErrorOutput()));
            }
        } finally {
            fclose($input);
            File::delete($credentials);
            DB::purge();
        }
    }

    /** Sandbox mode disables client commands such as \! and source. */
    private function supportsSandbox(string $binary): bool
    {
        $process = new Process([$binary, '--help']);
        $process->setTimeout(10)->run();

        return str_contains($process->getOutput(), '--sandbox');
    }

    private function importSqlite(string $databaseFile): void
    {
        $configured = config('database.connections.'.DB::getDefaultConnection().'.database');
        if (! is_string($configured) || $configured === '') {
            throw new RuntimeException('Die SQLite-Datenbank ist nicht vollständig konfiguriert.');
        }
        $target = str_starts_with($configured, DIRECTORY_SEPARATOR) ? $configured : base_path($configured);
        $partial = $target.'.restore-partial';
        DB::disconnect();
        try {
            if (! File::copy($databaseFile, $partial) || ! File::move($partial, $target)) {
                throw new RuntimeException('Die SQLite-Datenbank konnte nicht ersetzt werden.');
            }
            @chmod($target, 0600);
        } finally {
            File::delete($partial);
            DB::purge();
        }
    }

    private function databaseFileFromArchive(string $archive, string $directory): string
    {
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Das Backup ist kein lesbares ZIP-Archiv.');
        }
        try {
            $manifestJson = $zip->getFromName('manifest.json');
            if (! is_string($manifestJson)) {
                throw new RuntimeException('Das Backup enthält kein Manifest.');
            }
            $manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($manifest)
                || ($manifest['format'] ?? null) !== 'gymslunity-application-backup'
                || ($manifest['format_version'] ?? null) !== 1
                || ! in_array($manifest['backup_type'] ?? null, ['database', 'full'], true)) {
                throw new RuntimeException('Das Backup-Format oder seine Version wird nicht unterstützt.');
            }
            $signature = $manifest['signature'] ?? null;
            unset($manifest['signature']);
            if (! $this->signature->verify(json_encode($manifest, self::MANIFEST_FLAGS), $signature)) {
                throw new RuntimeException('Das Backup stammt nicht von dieser Installation oder wurde verändert.');
            }
            $archiveDriver = $manifest['database_driver'] ?? null;
            $currentDriver = DB::getDriverName();
            $sameFamily = in_array($archiveDriver, ['mysql', 'mariadb'], true)
                && in_array($currentDriver, ['mysql', 'mariadb'], true);
            if ($archiveDriver !== $currentDriver && ! $sameFamily) {
                throw new RuntimeException('Das Backup wurde für einen anderen Datenbanktreiber erstellt.');
            }
            if (($manifest['application_version'] ?? null) !== $this->applicationVersion()) {
                throw new RuntimeException('Das Backup wurde mit einer anderen GymSLunity-Version erstellt.');
            }
            $name = $currentDriver === 'sqlite' ? 'database.sqlite' : 'database.sql';
            $stat = $zip->statName($name);
            if (! is_array($stat) || $stat['size'] <= 0 || $stat['size'] > 2_147_483_648) {
                throw new RuntimeException('Der Datenbankexport im Backup fehlt oder ist unplausibel groß.');
            }
            $stream = $zip->getStream($name);
            if ($stream === false) {
                throw new RuntimeException('Der Datenbankexport im Backup konnte nicht gelesen werden.');
            }
            File::ensureDirectoryExists($directory, 0700, true);
            $target = $directory.'/'.$name;
            $output = fopen($target, 'wb');
            if ($output === false) {
                fclose($stream);
                throw new RuntimeException('Der Datenbankexport konnte nicht temporär gespeichert werden.');
            }
            try {
                if (stream_copy_to_stream($stream, $output) === false) {
                    throw new RuntimeException('Der Datenbankexport konnte nicht entpackt werden.');
                }
            } finally {
                fclose($stream);
                fclose($output);
            }
            @chmod($target, 0600);
            $checksums = $manifest['checksums'] ?? null;
            $expectedChecksum = is_array($checksums) ? ($checksums[$name] ?? null) : null;
            $actualChecksum = hash_file('sha256', $target);
            if (! is_string($expectedChecksum) || ! is_string($actualChecksum) || ! hash_equals($expectedChecksum, $actualChecksum)) {
                throw new RuntimeException('Die Prüfsumme des Datenbankexports ist ungültig.');
            }

            return $target;
        } catch (\JsonException $exception) {
            throw new RuntimeException('Das Manifest des Backups ist ungültig.', previous: $exception);
        } finally {
            $zip->close();
        }
    }

    private function optionFileValue(string $value): string
    {
        return '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value).'"';
    }

    private function createArchive(string $source, string $target): void
    {
        $zip = new ZipArchive;
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Das ZIP-Archiv konnte nicht angelegt werden.');
        }
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($iterator as $item) {
                if ($item->isLink()) {
                    continue;
                }
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($item->getPathname(), strlen($source) + 1));
                if ($item->isDir()) {
                    $zip->addEmptyDir($relative);
                } elseif (! $zip->addFile($item->getPathname(), $relative)) {
                    throw new RuntimeException('Eine Datei konnte dem Backup nicht hinzugefügt werden: '.$relative);
                }
            }
        } finally {
            if (! $zip->close()) {
                throw new RuntimeException('Das ZIP-Archiv konnte nicht abgeschlossen werden.');
            }
        }
    }

    private function copyDirectoryWithoutLinks(string $source, string $destination): void
    {
        File::ensureDirectoryExists($destination, 0700, true);
        $iterator = new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO);
        foreach ($iterator as $item) {
            if (! $item instanceof SplFileInfo) {
                throw new RuntimeException('Ein Eintrag aus storage/app konnte nicht gelesen werden.');
            }
            if ($item->isLink()) {
                continue;
            }
            $target = $destination.'/'.$item->getBasename();
            if ($item->isDir()) {
                $this->copyDirectoryWithoutLinks($item->getPathname(), $target);
            } elseif (! File::copy($item->getPathname(), $target)) {
                throw new RuntimeException('Eine Datei aus storage/app konnte nicht gesichert werden.');
            }
        }
    }

    private function applicationVersion(): ?string
    {
        $file = base_path('VERSION');

        return is_file($file) ? trim((string) File::get($file)) : null;
    }
}
