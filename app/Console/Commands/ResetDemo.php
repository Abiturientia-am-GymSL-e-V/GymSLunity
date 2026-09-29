<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Demo\DemoAccounts;
use App\Demo\DemoData;
use Illuminate\Console\Command;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Support\Facades\Storage;

class ResetDemo extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Die öffentliche Demo auf die Musterdaten zurücksetzen (nur mit DEMO_MODE=true)';

    public function handle(DemoData $data): int
    {
        if (! DemoAccounts::enabled()) {
            $this->error('Der Demo-Modus ist nicht aktiv. Ohne DEMO_MODE=true werden keine Daten gelöscht.');

            return self::FAILURE;
        }

        $this->call('down', ['--retry' => 60]);
        try {
            // Production prohibits destructive commands; the demo exists to be wiped.
            FreshCommand::prohibit(false);
            WipeCommand::prohibit(false);
            // Sessions and the cache live in the database and are dropped as well.
            if ($this->call('migrate:fresh', ['--force' => true]) !== self::SUCCESS) {
                return self::FAILURE;
            }
            foreach (['local', 'public'] as $disk) {
                $this->clearDisk($disk);
            }
            $data->seed();
            $this->call('cache:clear');
        } finally {
            $this->call('up');
        }

        $this->info('Die Demo wurde zurückgesetzt.');

        return self::SUCCESS;
    }

    private function clearDisk(string $disk): void
    {
        $storage = Storage::disk($disk);
        foreach ($storage->directories() as $directory) {
            $storage->deleteDirectory($directory);
        }
        $storage->delete(array_filter($storage->files(), fn (string $file): bool => $file !== '.gitignore'));
    }
}
