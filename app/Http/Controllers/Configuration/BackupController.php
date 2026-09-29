<?php

declare(strict_types=1);

namespace App\Http\Controllers\Configuration;

use App\Backup\ApplicationBackup;
use App\Backup\ConfigurationBackup;
use App\Http\Controllers\Controller;
use App\Support\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class BackupController extends Controller
{
    public function downloadConfiguration(ConfigurationBackup $backups): StreamedResponse|RedirectResponse
    {
        try {
            $contents = $backups->export();
            $filename = 'gymslunity-konfiguration-'.Clock::localNow()->format('Ymd-His').'.json';

            return response()->streamDownload(
                static fn () => print $contents,
                $filename,
                ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store, private'],
            );
        } catch (Throwable $exception) {
            report($exception);

            return to_route('configuration.system')->with(
                'backup_error',
                'Die Konfigurationssicherung konnte nicht erstellt werden. Details wurden im Serverprotokoll erfasst.',
            );
        }
    }

    public function downloadDatabase(ApplicationBackup $backups): BinaryFileResponse|RedirectResponse
    {
        try {
            // This existing cache directory belongs to PHP-FPM. A persistent
            // child directory could be created by CLI tests under another user
            // and then become inaccessible to web requests.
            $archive = $backups->create(
                databaseOnly: true,
                destinationDirectory: storage_path('framework/cache'),
            );

            return response()->download($archive, basename($archive), [
                'Content-Type' => 'application/zip',
                'Cache-Control' => 'no-store, private',
            ])->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            report($exception);

            return to_route('configuration.system')->with(
                'backup_error',
                'Die Datenbanksicherung konnte nicht erstellt werden. Bitte Serverprotokoll, Schreibrechte und die Installation von mariadb-dump beziehungsweise mysqldump prüfen.',
            );
        }
    }

    public function restoreConfiguration(Request $request, ConfigurationBackup $backups): RedirectResponse
    {
        $validated = $request->validate([
            'configuration_backup' => ['required', 'file', 'max:10240'],
            'confirmation' => ['required', 'string', Rule::in(['WIEDERHERSTELLEN'])],
        ], [
            'configuration_backup.required' => 'Bitte eine Konfigurationssicherung auswählen.',
            'configuration_backup.file' => 'Die Konfigurationssicherung konnte nicht gelesen werden.',
            'configuration_backup.max' => 'Die Konfigurationssicherung darf höchstens 10 MB groß sein.',
            'confirmation.in' => 'Zur Bestätigung bitte exakt WIEDERHERSTELLEN eingeben.',
        ]);

        try {
            $backups->restore($validated['configuration_backup']->getPathname());
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages([
                'configuration_backup' => $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'Die Konfiguration konnte nicht wiederhergestellt werden.',
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Einstellungen und Konfiguration wurden wiederhergestellt.']);

        return to_route('configuration.system');
    }

    public function restoreDatabase(Request $request, ApplicationBackup $backups): RedirectResponse
    {
        $validated = $request->validate([
            'database_backup' => ['required', 'file', 'max:524288'],
            'confirmation' => ['required', 'string', Rule::in(['WIEDERHERSTELLEN'])],
        ], [
            'database_backup.required' => 'Bitte eine Datenbanksicherung auswählen.',
            'database_backup.file' => 'Die Datenbanksicherung konnte nicht gelesen werden.',
            'database_backup.max' => 'Die Datenbanksicherung darf höchstens 512 MB groß sein.',
            'confirmation.in' => 'Zur Bestätigung bitte exakt WIEDERHERSTELLEN eingeben.',
        ]);

        $lock = fopen(storage_path('framework/backup-restore.lock'), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw ValidationException::withMessages([
                'database_backup' => 'Eine andere Datenbankwiederherstellung läuft bereits.',
            ]);
        }

        try {
            Artisan::call('down', ['--retry' => 60]);
            try {
                $safetyBackup = $backups->restoreDatabase($validated['database_backup']->getPathname());
                if (! app()->environment('testing')) {
                    Artisan::call('optimize:clear');
                }
            } catch (Throwable $exception) {
                report($exception);
                throw ValidationException::withMessages([
                    'database_backup' => $exception instanceof RuntimeException
                        ? $exception->getMessage()
                        : 'Die Datenbank konnte nicht wiederhergestellt werden.',
                ]);
            } finally {
                Artisan::call('up');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Die Datenbank wurde wiederhergestellt. Eine Sicherheitssicherung liegt unter '.basename($safetyBackup).'.',
        ]);

        return to_route('configuration.system');
    }
}
