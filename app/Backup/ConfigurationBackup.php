<?php

declare(strict_types=1);

namespace App\Backup;

use App\Configuration\ClubSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ConfigurationBackup
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    private const FORMAT = 'gymslunity-configuration-backup';

    private const VERSION = 2;

    /** @var list<string> */
    private const CLUB_COLUMNS = ['id', 'data', 'version', 'fields_version', 'created_at', 'updated_at'];

    /** @var list<string> */
    private const FIELD_COLUMNS = ['id', 'key', 'label', 'type', 'section', 'position', 'is_active', 'is_custom', 'required', 'filterable', 'show_in_table', 'selfservice_visible', 'selfservice_editable', 'options', 'max_length', 'created_at', 'updated_at'];

    /** @var list<string> */
    private const MAIL_COLUMNS = ['id', 'driver', 'from_address', 'from_name', 'reply_to_address', 'reply_to_name', 'smtp_host', 'smtp_port', 'smtp_security', 'smtp_username', 'smtp_password', 'smtp_timeout', 'smtp_local_domain', 'sendmail_path', 'version', 'created_at', 'updated_at'];

    public function export(): string
    {
        $club = $this->row('club_settings', self::CLUB_COLUMNS);
        $payload = [
            'club_settings' => $club,
            'member_field_definitions' => DB::table('member_field_definitions')
                ->orderBy('id')
                ->get(self::FIELD_COLUMNS)
                ->map(fn (object $row): array => (array) $row)
                ->all(),
            'mail_settings' => $this->row('mail_settings', self::MAIL_COLUMNS),
            'branding_logo' => $this->logo($club),
        ];

        return json_encode([
            'format' => self::FORMAT,
            'format_version' => self::VERSION,
            'created_at' => now()->toIso8601String(),
            'application_version' => $this->applicationVersion(),
            'payload' => $payload,
            'checksum' => $this->checksum($payload),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
    }

    public function restore(string $file): void
    {
        try {
            $document = json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('Die Konfigurationssicherung enthält kein gültiges JSON.', previous: $exception);
        }
        if (! is_array($document)
            || ($document['format'] ?? null) !== self::FORMAT
            || ! in_array($document['format_version'] ?? null, [1, self::VERSION], true)
            || ! is_array($document['payload'] ?? null)
            || ! is_string($document['checksum'] ?? null)) {
            throw new RuntimeException('Format oder Version der Konfigurationssicherung wird nicht unterstützt.');
        }

        $payload = $document['payload'];
        if (! hash_equals($this->checksum($payload), $document['checksum'])) {
            throw new RuntimeException('Die Prüfsumme der Konfigurationssicherung ist ungültig.');
        }
        if ($document['format_version'] === 1) {
            $payload = $this->upgradeVersionOnePayload($payload);
        }
        $club = $this->validatedRow($payload['club_settings'] ?? null, self::CLUB_COLUMNS, 'Vereinskonfiguration');
        $mail = $this->validatedRow($payload['mail_settings'] ?? null, self::MAIL_COLUMNS, 'E-Mail-Konfiguration');
        $fields = $payload['member_field_definitions'] ?? null;
        if (($club['id'] ?? null) !== 1 || ($mail['id'] ?? null) !== 1 || ! is_array($fields) || $fields === []) {
            throw new RuntimeException('Die Konfigurationssicherung ist unvollständig.');
        }
        $validatedFields = [];
        foreach ($fields as $field) {
            $validatedFields[] = $this->validatedRow($field, self::FIELD_COLUMNS, 'Mitgliedsfelder');
        }
        $keys = array_column($validatedFields, 'key');
        if (count($keys) !== count(array_unique($keys)) || count(array_column($validatedFields, 'id')) !== count(array_unique(array_column($validatedFields, 'id')))) {
            throw new RuntimeException('Die Konfigurationssicherung enthält doppelte Mitgliedsfelder.');
        }

        $logo = $this->validatedLogo($payload['branding_logo'] ?? null, $club);
        $oldLogo = $this->clubSettings->logoPath();
        $oldLogoContents = $oldLogo === null ? null : File::get($oldLogo);
        $newLogo = null;

        try {
            $newLogo = $this->writeLogo($logo);
            DB::transaction(function () use ($club, $mail, $validatedFields): void {
                $currentClub = DB::table('club_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
                $currentMail = DB::table('mail_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
                $club['version'] = max((int) $currentClub->version, (int) $club['version']) + 1;
                $club['fields_version'] = max((int) $currentClub->fields_version, (int) $club['fields_version']) + 1;
                $club['updated_at'] = now();
                $mail['version'] = max((int) $currentMail->version, (int) $mail['version']) + 1;
                $mail['updated_at'] = now();
                DB::table('club_settings')->where('id', 1)->update($club);
                DB::table('mail_settings')->where('id', 1)->update($mail);
                DB::table('member_field_definitions')->delete();
                DB::table('member_field_definitions')->insert($validatedFields);
            });
            // The restore bypasses model events; drop the cached configuration.
            app(ClubSettings::class)->refresh();
        } catch (Throwable $exception) {
            $this->rollBackLogo($newLogo, $oldLogo, $oldLogoContents);
            throw $exception;
        }

        if ($oldLogo !== null && $oldLogo !== $newLogo) {
            File::delete($oldLogo);
        }
    }

    /** @param list<string> $columns
     * @return array<string, mixed>
     */
    private function row(string $table, array $columns): array
    {
        $row = DB::table($table)->where('id', 1)->first($columns);
        if ($row === null) {
            throw new RuntimeException('Die Konfigurationstabelle '.$table.' ist unvollständig.');
        }

        return (array) $row;
    }

    /** @param array<string, mixed> $club
     * @return array{path: string, contents: string}|null
     */
    private function logo(array $club): ?array
    {
        $data = json_decode((string) ($club['data'] ?? ''), true);
        $path = is_array($data) ? ($data['logo_path'] ?? null) : null;
        if (! is_string($path) || ! preg_match('/^branding\/logo-[a-f0-9-]{36}\.png$/', $path) || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return ['path' => $path, 'contents' => base64_encode(Storage::disk('local')->get($path))];
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private function validatedRow(mixed $value, array $columns, string $label): array
    {
        if (! is_array($value) || array_keys($value) !== $columns) {
            throw new RuntimeException($label.' hat nicht die erwartete Struktur.');
        }
        foreach ($value as $entry) {
            if (! is_scalar($entry) && $entry !== null) {
                throw new RuntimeException($label.' enthält einen ungültigen Wert.');
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $club
     * @return array{path: string, contents: string}|null
     */
    private function validatedLogo(mixed $value, array $club): ?array
    {
        $data = json_decode((string) ($club['data'] ?? ''), true);
        if (! is_array($data)) {
            throw new RuntimeException('Die Vereinskonfiguration enthält ungültige Daten.');
        }
        $expectedPath = $data['logo_path'] ?? null;
        if ($expectedPath === null) {
            if ($value !== null) {
                throw new RuntimeException('Die Logodatei passt nicht zur Vereinskonfiguration.');
            }

            return null;
        }
        if (! is_string($expectedPath)
            || ! preg_match('/^branding\/logo-[a-f0-9-]{36}\.png$/', $expectedPath)
            || ! is_array($value)
            || array_keys($value) !== ['path', 'contents']
            || ($value['path'] ?? null) !== $expectedPath
            || ! is_string($value['contents'] ?? null)) {
            throw new RuntimeException('Die Logodatei in der Konfigurationssicherung ist ungültig.');
        }
        $decoded = base64_decode($value['contents'], true);
        if ($decoded === false || strlen($decoded) > 5 * 1024 * 1024 || ! str_starts_with($decoded, "\x89PNG\r\n\x1a\n")) {
            throw new RuntimeException('Die Logodatei in der Konfigurationssicherung ist beschädigt.');
        }

        return ['path' => $expectedPath, 'contents' => $decoded];
    }

    /** @param array{path: string, contents: string}|null $logo */
    private function writeLogo(?array $logo): ?string
    {
        if ($logo === null) {
            return null;
        }
        if (! Storage::disk('local')->put($logo['path'], $logo['contents'])) {
            throw new RuntimeException('Das Vereinslogo konnte nicht wiederhergestellt werden.');
        }

        return Storage::disk('local')->path($logo['path']);
    }

    private function rollBackLogo(?string $newLogo, ?string $oldLogo, ?string $oldLogoContents): void
    {
        if ($newLogo !== null && $newLogo !== $oldLogo) {
            File::delete($newLogo);
        }
        if ($oldLogo !== null && $oldLogoContents !== null) {
            File::put($oldLogo, $oldLogoContents);
        }
    }

    /** @param array<string, mixed> $payload */
    private function checksum(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function upgradeVersionOnePayload(array $payload): array
    {
        $fields = $payload['member_field_definitions'] ?? null;
        if (! is_array($fields)) {
            return $payload;
        }
        $payload['member_field_definitions'] = array_map(function (mixed $field): mixed {
            if (! is_array($field) || array_key_exists('selfservice_visible', $field)) {
                return $field;
            }
            $upgraded = [];
            foreach ($field as $key => $value) {
                $upgraded[$key] = $value;
                if ($key === 'show_in_table') {
                    $upgraded['selfservice_visible'] = (bool) ($field['selfservice_editable'] ?? false);
                }
            }

            return $upgraded;
        }, $fields);

        return $payload;
    }

    private function applicationVersion(): ?string
    {
        $file = base_path('VERSION');

        return is_file($file) ? trim((string) File::get($file)) : null;
    }
}
