<?php

declare(strict_types=1);

return [
    'path' => env('BACKUP_PATH', storage_path('backups')),
    'retention_days' => max(1, (int) env('BACKUP_RETENTION_DAYS', 30)),
];
