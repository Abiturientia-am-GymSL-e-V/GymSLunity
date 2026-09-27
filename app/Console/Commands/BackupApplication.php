<?php

namespace App\Console\Commands;

use App\Backup\ApplicationBackup;
use Illuminate\Console\Command;
use Throwable;

class BackupApplication extends Command
{
    protected $signature = 'app:backup
        {--database-only : Nur einen konsistenten Datenbankexport sichern}
        {--prune : Lokale Archive löschen, die älter als die Aufbewahrungsfrist sind}';

    protected $description = 'Erzeugt ein lokales, wiederherstellbares GymSLunity-Backup';

    public function handle(ApplicationBackup $backups): int
    {
        try {
            $archive = $backups->create((bool) $this->option('database-only'));
            $deleted = $this->option('prune') ? $backups->prune($archive) : 0;
            $this->components->info('Backup erstellt: '.$archive);
            if ($deleted > 0) {
                $this->line($deleted.' abgelaufene lokale Backup(s) gelöscht.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error('Backup fehlgeschlagen: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
