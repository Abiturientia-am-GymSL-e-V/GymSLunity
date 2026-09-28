<?php

declare(strict_types=1);

namespace App\Members;

use App\Configuration\ClubSettings;
use App\Models\Member;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class MemberCsvImport
{
    private const MAX_ROWS = 1000;

    private const MAX_COLUMNS = 200;

    /** @var array<string, list<string>> */
    private const HEADER_ALIASES = [
        'member_number' => ['mitgliedsnummer', 'mitgliedsnr', 'mitgliednr', 'mitglieds-nr', 'membernumber'],
        'first_name' => ['vorname', 'firstname', 'givenname'],
        'middle_name' => ['zweitname', 'weiterevornamen', 'middlename'],
        'last_name' => ['nachname', 'familienname', 'lastname', 'surname'],
        'email' => ['e-mail', 'emailadresse', 'mailadresse'],
        'mobile_phone' => ['mobil', 'mobilnummer', 'handy', 'handynummer', 'mobilephone'],
        'street' => ['strasse', 'straße', 'strassehausnummer', 'anschrift'],
        'postal_code' => ['plz', 'postleitzahl', 'postalcode', 'zipcode'],
        'city' => ['ort', 'wohnort', 'stadt', 'city'],
        'country' => ['land', 'country'],
        'birth_date' => ['geburtsdatum', 'birthday', 'dateofbirth'],
        'gender' => ['geschlecht', 'anredegeschlecht', 'gender'],
        'membership_type' => ['mitgliedsart', 'mitgliedschaft', 'mitgliedstyp', 'membershiptype'],
        'department_role' => ['funktionabteilung', 'abteilungsfunktion'],
        'club_role' => ['funktionhauptverein', 'vereinsfunktion'],
        'is_honorary' => ['ehrenmitglied', 'ehrenmitgliedschaft'],
        'joined_at' => ['eintritt', 'eintrittsdatum', 'mitgliedseit', 'joinedat'],
        'left_at' => ['austritt', 'austrittsdatum', 'leftat'],
        'deceased_at' => ['sterbedatum', 'verstorbenam'],
        'payment_method' => ['zahlungsart', 'zahlweise', 'paymentmethod'],
        'iban' => ['iban', 'kontonummeriban'],
        'mandate_reference' => ['mandatsreferenz', 'mandatereference'],
        'mandate_signed_at' => ['mandatsdatum', 'mandatunterzeichnetam'],
        'mandate_type' => ['mandatsart', 'mandatstyp', 'mandatetype'],
    ];

    public function __construct(private readonly ClubSettings $clubSettings) {}

    /** @return list<string> */
    public function columns(): array
    {
        return ['member_number', ...array_column(MemberFields::directoryFields(), 'key')];
    }

    /** @return list<string> */
    public function requiredColumns(): array
    {
        $required = ['member_number'];
        foreach (MemberFields::sections() as $section) {
            foreach ($section['fields'] as $field) {
                if ($field['key'] === 'mandate_type') {
                    continue;
                }
                if ($field['required'] || ($field['type'] === 'boolean' && ! $field['custom'])) {
                    $required[] = $field['key'];
                }
            }
        }

        return array_values(array_unique($required));
    }

    /** @return array<string, mixed> */
    public function preview(UploadedFile $file, User $user): array
    {
        $content = $this->utf8($file->getContent());
        $delimiter = $this->delimiter($content);
        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) {
            throw ValidationException::withMessages(['csv' => 'Die CSV-Datei konnte nicht gelesen werden.']);
        }
        fwrite($handle, $content);
        rewind($handle);

        try {
            $headers = fgetcsv($handle, separator: $delimiter, escape: '');
            if (! is_array($headers)) {
                throw ValidationException::withMessages(['csv' => 'Die CSV-Datei enthält keine Kopfzeile.']);
            }
            $headers = array_map(fn (mixed $header): string => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $header) ?? ''), $headers);
            $this->validateSourceHeaders($headers);
            $rawRows = [];
            $line = 1;
            while (($cells = fgetcsv($handle, separator: $delimiter, escape: '')) !== false) {
                $line++;
                if (count($rawRows) >= self::MAX_ROWS) {
                    throw ValidationException::withMessages(['csv' => 'Pro Import sind höchstens '.self::MAX_ROWS.' Datenzeilen erlaubt.']);
                }
                if (count($cells) === 1 && trim((string) $cells[0]) === '') {
                    continue;
                }
                if (count($cells) !== count($headers)) {
                    $rawRows[] = ['line' => $line, 'values' => [], 'structural_error' => 'Die Anzahl der Werte stimmt nicht mit der Kopfzeile überein.'];

                    continue;
                }
                $rawRows[] = ['line' => $line, 'values' => array_combine($headers, array_map(fn (mixed $value): string => trim((string) $value), $cells))];
            }
        } finally {
            fclose($handle);
        }

        if ($rawRows === []) {
            throw ValidationException::withMessages(['csv' => 'Die CSV-Datei enthält keine Datenzeilen.']);
        }

        $token = Str::random(48);
        $payload = [
            'user_id' => $user->getKey(), 'configuration_version' => $this->clubSettings->fieldsVersion(),
            'stage' => 'mapping', 'source_headers' => $headers, 'source_rows' => $rawRows,
            'delimiter' => $delimiter, 'mapping' => $this->suggestMapping($headers),
        ];
        $native = array_diff($headers, $this->columns()) === []
            && array_diff($this->requiredColumns(), $headers) === [];
        if ($native) {
            $payload = $this->finalizeMapping($payload, array_map(
                fn (string $header): array => ['source' => $header, 'target' => $header],
                $headers,
            ), false);
        }
        Cache::put($this->cacheKey($token), $payload, now()->addMinutes(30));

        return ['token' => $token, 'stage' => $payload['stage']];
    }

    /** @param list<array{source: string, target: string|null}> $mapping
     * @return array<string, mixed>
     */
    public function mapColumns(string $token, User $user, array $mapping): array
    {
        $payload = $this->get($token, $user);
        if ($payload === null || ($payload['stage'] ?? null) !== 'mapping') {
            throw ValidationException::withMessages(['mapping' => 'Die Spaltenzuordnung ist abgelaufen. Bitte die CSV-Datei erneut hochladen.']);
        }
        if ($payload['configuration_version'] !== $this->clubSettings->fieldsVersion()) {
            throw ValidationException::withMessages(['mapping' => 'Die Mitgliedsfelder wurden geändert. Bitte die CSV-Datei erneut hochladen.']);
        }
        $payload = $this->finalizeMapping($payload, $mapping, true);
        Cache::put($this->cacheKey($token), $payload, now()->addMinutes(30));

        return $payload;
    }

    /** @return array<string, mixed>|null */
    public function get(string $token, User $user): ?array
    {
        $payload = Cache::get($this->cacheKey($token));

        return is_array($payload) && ($payload['user_id'] ?? null) === $user->getKey() ? $payload : null;
    }

    public function import(string $token, User $user, CreateMember $create): int
    {
        $payload = $this->get($token, $user);
        if ($payload === null) {
            throw ValidationException::withMessages(['form' => 'Die Importvorschau ist abgelaufen. Bitte die CSV-Datei erneut prüfen.']);
        }
        if (($payload['stage'] ?? null) !== 'preview') {
            throw ValidationException::withMessages(['form' => 'Bitte zuerst die CSV-Spalten den Mitgliedsfeldern zuordnen.']);
        }
        if ($payload['configuration_version'] !== $this->clubSettings->fieldsVersion()) {
            throw ValidationException::withMessages(['form' => 'Die Mitgliedsfelder wurden seit der Vorschau geändert. Bitte die CSV-Datei erneut prüfen.']);
        }
        $current = $this->validateRows($payload['raw_rows']);
        if ($current['error_count'] > 0) {
            Cache::put($this->cacheKey($token), [...$payload, ...$current], now()->addMinutes(30));
            throw ValidationException::withMessages(['form' => 'Der Import enthält inzwischen Fehler. Bitte die aktualisierte Vorschau prüfen.']);
        }

        DB::transaction(function () use ($current, $user, $create, $payload): void {
            foreach ($current['valid_rows'] as $row) {
                $number = (int) $row['values']['member_number'];
                $values = $row['values'];
                unset($values['member_number']);
                $create->handle($user, $number, $values, $payload['configuration_version']);
            }
        }, attempts: 3);
        Cache::forget($this->cacheKey($token));

        return count($current['valid_rows']);
    }

    /** @param array<string, mixed> $payload
     * @param  list<array{source: string, target: string|null}>  $mapping
     * @return array<string, mixed>
     */
    private function finalizeMapping(array $payload, array $mapping, bool $manual): array
    {
        $headers = $payload['source_headers'];
        if (array_column($mapping, 'source') !== $headers) {
            throw ValidationException::withMessages(['mapping' => 'Die übermittelte Spaltenzuordnung passt nicht mehr zur CSV-Datei.']);
        }
        $allowed = array_fill_keys($this->columns(), true);
        $targets = [];
        foreach ($mapping as $entry) {
            $target = $entry['target'] ?? null;
            if ($target === null || $target === '') {
                continue;
            }
            if (! isset($allowed[$target])) {
                throw ValidationException::withMessages(['mapping' => 'Ein ausgewähltes Mitgliedsfeld ist nicht verfügbar.']);
            }
            if (isset($targets[$target])) {
                throw ValidationException::withMessages(['mapping' => 'Jedes Mitgliedsfeld darf nur einer CSV-Spalte zugeordnet werden.']);
            }
            $targets[$target] = $entry['source'];
        }
        $missing = array_diff($this->requiredColumns(), array_keys($targets));
        if ($missing !== []) {
            throw ValidationException::withMessages(['mapping' => 'Bitte alle Pflichtfelder zuordnen: '.implode(', ', $missing).'.']);
        }

        $mappedRows = [];
        foreach ($payload['source_rows'] as $row) {
            if (isset($row['structural_error'])) {
                $mappedRows[] = $row;

                continue;
            }
            $values = [];
            foreach ($mapping as $entry) {
                $target = $entry['target'] ?? null;
                if (is_string($target) && $target !== '') {
                    $values[$target] = $row['values'][$entry['source']] ?? '';
                }
            }
            $mappedRows[] = ['line' => $row['line'], 'values' => $values];
        }
        $result = $this->validateRows($mappedRows);

        return [
            ...$payload,
            'stage' => 'preview',
            'manual_mapping' => $manual,
            'headers' => array_keys($targets),
            'raw_rows' => $mappedRows,
            'mapping' => $mapping,
            ...$result,
        ];
    }

    /** @param list<string> $headers
     * @return list<array{source: string, target: string|null}>
     */
    private function suggestMapping(array $headers): array
    {
        $labels = ['member_number' => 'Mitgliedsnummer'];
        foreach (MemberFields::directoryFields() as $field) {
            $labels[$field['key']] = $field['label'];
        }
        $lookup = [];
        foreach ($labels as $key => $label) {
            foreach ([$key, $label, ...(self::HEADER_ALIASES[$key] ?? [])] as $candidate) {
                $normalized = $this->normalizeHeader($candidate);
                if ($normalized !== '' && ! isset($lookup[$normalized])) {
                    $lookup[$normalized] = $key;
                }
            }
        }
        $used = [];

        return array_map(function (string $header) use ($lookup, &$used): array {
            $matched = $lookup[$this->normalizeHeader($header)] ?? null;
            $target = is_string($matched) ? $matched : null;
            if ($target !== null && isset($used[$target])) {
                $target = null;
            }
            if ($target !== null) {
                $used[$target] = true;
            }

            return ['source' => $header, 'target' => $target];
        }, $headers);
    }

    private function normalizeHeader(string $header): string
    {
        $header = strtr(mb_strtolower(trim($header)), [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        ]);

        return preg_replace('/[^a-z0-9]+/', '', $header) ?? '';
    }

    private function utf8(string $content): string
    {
        if (str_starts_with($content, "\xFF\xFE")) {
            $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($content, "\xFE\xFF")) {
            $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE');
        } elseif (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        if (! mb_check_encoding($content, 'UTF-8')) {
            throw ValidationException::withMessages(['csv' => 'Die Zeichenkodierung der CSV-Datei konnte nicht gelesen werden.']);
        }

        return preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
    }

    private function delimiter(string $content): string
    {
        $best = ';';
        $bestCount = 0;
        foreach ([';', ',', "\t"] as $candidate) {
            $handle = fopen('php://temp', 'w+b');
            if ($handle === false) {
                continue;
            }
            fwrite($handle, $content);
            rewind($handle);
            $row = fgetcsv($handle, separator: $candidate, escape: '');
            fclose($handle);
            $count = is_array($row) ? count($row) : 0;
            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }
        if ($bestCount < 2) {
            throw ValidationException::withMessages(['csv' => 'Das Trennzeichen konnte nicht erkannt werden. Unterstützt werden Semikolon, Komma und Tabulator.']);
        }

        return $best;
    }

    /** @param list<string> $headers */
    private function validateSourceHeaders(array $headers): void
    {
        if (in_array('', $headers, true) || count($headers) !== count(array_unique($headers))) {
            throw ValidationException::withMessages(['csv' => 'Die Kopfzeile enthält leere oder doppelte Spaltennamen.']);
        }
        if (count($headers) > self::MAX_COLUMNS) {
            throw ValidationException::withMessages(['csv' => 'Die CSV-Datei darf höchstens '.self::MAX_COLUMNS.' Spalten enthalten.']);
        }
    }

    /** @param list<array<string, mixed>> $rawRows
     * @return array{rows: list<array<string, mixed>>, valid_rows: list<array<string, mixed>>, error_count: int}
     */
    private function validateRows(array $rawRows): array
    {
        $rows = [];
        $validRows = [];
        $seen = [];
        $existing = Member::query()->pluck('member_number')->mapWithKeys(fn (int $number): array => [$number => true])->all();
        foreach ($rawRows as $rawRow) {
            $errors = isset($rawRow['structural_error']) ? [$rawRow['structural_error']] : [];
            $values = $this->normalize($rawRow['values'] ?? []);
            $number = $values['member_number'] ?? null;
            $validator = Validator::make(
                array_replace(array_fill_keys(MemberFields::writable(), null), $values),
                ['member_number' => ['bail', 'required', 'integer', 'min:1', 'max:4294967295', Rule::notIn(array_keys($existing))], ...MemberValidation::rules(new Member)],
                ['member_number.not_in' => 'Diese Mitgliedsnummer ist bereits vergeben.', ...MemberValidation::messages()],
                ['member_number' => 'Mitgliedsnummer', ...MemberValidation::attributes()],
            );
            if ($validator->fails()) {
                $errors = [...$errors, ...$validator->errors()->all()];
            }
            if (is_int($number) && isset($seen[$number])) {
                $errors[] = 'Diese Mitgliedsnummer kommt mehrfach in der CSV-Datei vor (zuerst in Zeile '.$seen[$number].').';
            } elseif (is_int($number)) {
                $seen[$number] = $rawRow['line'];
            }
            try {
                MemberValidation::validateDates($values);
            } catch (ValidationException $exception) {
                $errors = [...$errors, ...$exception->validator->errors()->all()];
            }
            $row = [
                'line' => $rawRow['line'], 'member_number' => $number,
                'name' => trim(implode(' ', array_filter([$values['first_name'] ?? null, $values['last_name'] ?? null]))),
                'values' => $values, 'errors' => array_values(array_unique($errors)),
            ];
            $rows[] = $row;
            if ($row['errors'] === []) {
                $validRows[] = $row;
            }
        }

        return ['rows' => $rows, 'valid_rows' => $validRows, 'error_count' => count($rows) - count($validRows)];
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function normalize(array $values): array
    {
        if (! isset($values['mandate_type']) || trim((string) $values['mandate_type']) === '') {
            $values['mandate_type'] = 'recurring';
        }

        $fields = [];
        foreach (MemberFields::sections() as $section) {
            foreach ($section['fields'] as $field) {
                $fields[$field['key']] = $field;
            }
        }
        $normalized = [];
        foreach ($values as $key => $value) {
            $value = is_string($value) ? trim($value) : $value;
            if ($value === '') {
                $normalized[$key] = null;

                continue;
            }
            if ($key === 'member_number') {
                $normalized[$key] = is_string($value) && preg_match('/^\d+$/', $value) ? (int) $value : $value;

                continue;
            }
            $field = $fields[$key] ?? null;
            if (! is_array($field)) {
                continue;
            }
            $normalized[$key] = match ($field['type']) {
                'boolean' => $this->boolean($value),
                'number' => is_string($value) && preg_match('/^-?\d+$/', $value) ? (int) $value : $value,
                'decimal' => is_string($value) ? str_replace(',', '.', $value) : $value,
                default => $key === 'iban' && is_string($value) ? strtoupper(preg_replace('/\s+/', '', $value) ?? '') : $value,
            };
        }

        return $normalized;
    }

    private function boolean(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return match (mb_strtolower($value)) {
            '1', 'true', 'ja', 'yes' => true,
            '0', 'false', 'nein', 'no' => false,
            default => $value,
        };
    }

    private function cacheKey(string $token): string
    {
        if (! preg_match('/^[A-Za-z0-9]{48}$/', $token)) {
            throw new RuntimeException('Ungültiger Import-Schlüssel.');
        }

        return 'member-import:'.$token;
    }
}
