<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class InstallApplication extends Command
{
    protected $signature = 'app:install
        {--force : Installation ohne Rückfrage in der Produktivumgebung ausführen}
        {--no-user : Kein Administratorkonto anlegen}
        {--skip-migrations : Datenbankmigrationen nicht ausführen}
        {--skip-storage-link : Öffentlichen Storage-Link nicht anlegen}';

    protected $description = 'GymSLunity nach dem Bearbeiten der .env sicher einrichten';

    public function handle(): int
    {
        $this->components->info('GymSLunity-Installation');

        if (! is_file(base_path('.env'))) {
            $this->components->error('Die Datei .env fehlt. Zuerst .env.example kopieren und die Serverwerte anpassen.');

            return self::FAILURE;
        }

        if (app()->isProduction() && ! $this->option('force') && ! $this->confirm('Produktivinstallation jetzt fortsetzen?')) {
            return self::FAILURE;
        }

        if (! $this->checkRequirements()) {
            return self::FAILURE;
        }

        if (blank(config('app.key'))) {
            $this->components->info('Ein neuer Anwendungsschlüssel wird erzeugt.');
            if ($this->callSilent('key:generate', ['--force' => true]) !== self::SUCCESS || ! $this->reloadApplicationKey()) {
                $this->components->error('Der Anwendungsschlüssel konnte nicht geladen werden.');

                return self::FAILURE;
            }
        } else {
            $this->components->info('Ein Anwendungsschlüssel ist bereits gesetzt und bleibt unverändert.');
        }

        if (! $this->prepareSqliteDatabase()) {
            return self::FAILURE;
        }

        try {
            DB::connection()->getPdo();
            $this->components->info('Datenbankverbindung erfolgreich.');
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error('Datenbankverbindung fehlgeschlagen. Bitte DB_CONNECTION und die zugehörigen DB_*-Werte prüfen.');

            return self::FAILURE;
        }

        if (! $this->option('skip-migrations')) {
            if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        if (! $this->option('skip-storage-link')) {
            $this->callSilent('storage:link');
        }

        if (! $this->option('no-user')) {
            if (User::query()->exists()) {
                $this->components->info('Es ist bereits mindestens ein Benutzerkonto vorhanden; es wird kein weiteres angelegt.');
            } elseif ($this->call('app:create-user', ['--role' => ['admin']]) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        $this->callSilent(app()->isProduction() ? 'optimize' : 'optimize:clear');
        $this->newLine();
        $this->components->info('GymSLunity ist eingerichtet. Für Datenbank-Warteschlangen zusätzlich einen dauerhaft laufenden queue:work-Prozess einrichten.');

        return self::SUCCESS;
    }

    private function checkRequirements(): bool
    {
        $missing = [];

        if (version_compare(PHP_VERSION, '8.3.0', '<')) {
            $missing[] = 'PHP 8.3 oder neuer';
        }

        foreach (['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'mbstring', 'openssl', 'pcre', 'pdo', 'session', 'tokenizer', 'xml', 'zip'] as $extension) {
            if (! extension_loaded($extension)) {
                $missing[] = 'PHP-Erweiterung '.$extension;
            }
        }

        foreach ([storage_path(), base_path('bootstrap/cache')] as $directory) {
            if (! is_dir($directory) || ! is_writable($directory)) {
                $missing[] = 'Schreibrecht für '.$directory;
            }
        }

        if ($missing !== []) {
            $this->components->error('Fehlende Voraussetzungen: '.implode(', ', $missing));

            return false;
        }

        $this->components->info('PHP-Version, Erweiterungen und Schreibrechte wurden geprüft.');

        return true;
    }

    private function reloadApplicationKey(): bool
    {
        $contents = file_get_contents(base_path('.env'));
        if (! is_string($contents) || preg_match('/^APP_KEY=(.*)$/m', $contents, $matches) !== 1) {
            return false;
        }

        $key = trim(trim($matches[1]), "\"'");
        if ($key === '') {
            return false;
        }

        config(['app.key' => $key]);

        return true;
    }

    private function prepareSqliteDatabase(): bool
    {
        if (config('database.default') !== 'sqlite') {
            return true;
        }

        $database = config('database.connections.sqlite.database');
        if (! is_string($database) || $database === '' || $database === ':memory:' || is_file($database)) {
            return true;
        }

        $directory = dirname($database);
        if (! is_dir($directory) || ! is_writable($directory) || ! touch($database)) {
            $this->components->error('Die SQLite-Datei konnte nicht angelegt werden: '.$database);

            return false;
        }

        $this->components->info('SQLite-Datenbank angelegt: '.$database);

        return true;
    }
}
