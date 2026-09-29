<?php

declare(strict_types=1);

namespace App\Backup;

use RuntimeException;

/**
 * Binds backups to this installation: only archives signed with a key
 * derived from APP_KEY can be restored, so an uploaded file can never
 * smuggle foreign SQL or configuration into the instance.
 */
final class BackupSignature
{
    public function sign(string $data): string
    {
        return hash_hmac('sha256', $data, $this->key());
    }

    public function verify(string $data, mixed $signature): bool
    {
        return is_string($signature) && hash_equals($this->sign($data), $signature);
    }

    private function key(): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Ohne APP_KEY können Backups weder signiert noch geprüft werden.');
        }
        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        return hash_hmac('sha256', 'gymslunity-backup-signature', $key, true);
    }
}
