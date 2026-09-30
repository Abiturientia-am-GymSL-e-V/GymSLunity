<?php

declare(strict_types=1);

namespace App\Members;

use App\Configuration\ClubSettings;
use App\Models\Member;
use App\Models\User;
use App\Support\CsvUpload;
use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Imports department, office and honor assignments with periods from a CSV
 * file, e.g. the board since the club was founded. One row is one
 * assignment of an existing member. The preview runs every row through
 * MemberAssignments inside a transaction that is rolled back, so it applies
 * the same duplicate and overlap rules as the import. The import itself
 * stores all rows or none.
 */
final class AssignmentCsvImport
{
    public const COLUMNS = ['Mitgliedsnummer', 'Feld', 'Auswahl', 'Von', 'Bis', 'Notiz'];

    private const MAX_ROWS = 2000;

    /** @var array<string, list<string>> */
    private const ALIASES = [
        'member_number' => ['mitgliedsnummer', 'mitgliedsnr', 'nr', 'nummer', 'membernumber'],
        'field' => ['feld', 'field', 'bereich'],
        'option' => ['auswahl', 'option', 'wert', 'amt', 'funktion', 'abteilung', 'ehrung'],
        'starts_on' => ['von', 'beginn', 'start', 'datum', 'ab', 'startson'],
        'ends_on' => ['bis', 'ende', 'endson'],
        'note' => ['notiz', 'note', 'bemerkung', 'anmerkung'],
    ];

    public function __construct(private readonly ClubSettings $clubSettings, private readonly MemberAssignments $assignments) {}

    /** Reads and checks the file; returns the token of the stored preview. */
    public function preview(UploadedFile $file, User $user): string
    {
        $csv = CsvUpload::read($file, self::MAX_ROWS, 20);
        $columns = $this->columns($csv['headers']);
        $fields = MemberFields::temporalFields();
        $rows = array_map(fn (array $row): array => $this->parse($row, $columns, $fields), $csv['rows']);
        $rows = $this->dryRun($rows, $user);

        $token = Str::random(48);
        Cache::put($this->cacheKey($token), [
            'user_id' => $user->getKey(), 'configuration_version' => $this->clubSettings->fieldsVersion(), 'rows' => $rows,
        ], now()->addMinutes(30));

        return $token;
    }

    /** @return array<string, mixed>|null with rows and configuration_version */
    public function get(string $token, User $user): ?array
    {
        $payload = Cache::get($this->cacheKey($token));

        return is_array($payload) && ($payload['user_id'] ?? null) === $user->getKey() ? $payload : null;
    }

    /**
     * Rows of a stored preview.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<mixed>>
     */
    public static function rows(array $payload): array
    {
        return array_values(array_filter(is_array($payload['rows'] ?? null) ? $payload['rows'] : [], is_array(...)));
    }

    /** @param list<array<mixed>> $rows */
    public static function errorCount(array $rows): int
    {
        return count(array_filter($rows, fn (array $row): bool => ($row['errors'] ?? []) !== []));
    }

    /** Stores all assignments of the preview; returns their number. */
    public function import(string $token, User $user): int
    {
        $payload = $this->get($token, $user);
        if ($payload === null) {
            throw ValidationException::withMessages(['form' => 'Die Importvorschau ist abgelaufen. Bitte die CSV-Datei erneut prüfen.']);
        }
        if ($payload['configuration_version'] !== $this->clubSettings->fieldsVersion()) {
            throw ValidationException::withMessages(['form' => 'Die Mitgliedsfelder wurden seit der Vorschau geändert. Bitte die CSV-Datei erneut prüfen.']);
        }
        $rows = self::rows($payload);
        if (self::errorCount($rows) > 0) {
            throw ValidationException::withMessages(['form' => 'Die Vorschau enthält Fehler. Bitte die CSV-Datei korrigieren und erneut prüfen.']);
        }

        DB::transaction(function () use ($rows, $user): void {
            foreach ($rows as $row) {
                try {
                    $this->add($row, $user);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(['form' => 'Zeile '.$row['line'].': '.collect($exception->errors())->flatten()->first().' Es wurde nichts importiert.']);
                }
            }
        }, attempts: 3);
        Cache::forget($this->cacheKey($token));

        return count($rows);
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, string> column key => header in the file
     */
    private function columns(array $headers): array
    {
        $columns = [];
        foreach ($headers as $header) {
            $normalized = CsvUpload::normalizeHeader($header);
            foreach (self::ALIASES as $key => $aliases) {
                if (! isset($columns[$key]) && in_array($normalized, $aliases, true)) {
                    $columns[$key] = $header;
                }
            }
        }
        foreach (['member_number' => 'Mitgliedsnummer', 'field' => 'Feld', 'option' => 'Auswahl'] as $key => $label) {
            if (! isset($columns[$key])) {
                throw ValidationException::withMessages(['csv' => 'Die Spalte „'.$label.'“ fehlt. Erwartet werden die Spalten '.implode(', ', self::COLUMNS).'.']);
            }
        }

        return $columns;
    }

    /**
     * @param  array{line: int, values: array<string, string>, structural_error?: string}  $raw
     * @param  array<string, string>  $columns
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function parse(array $raw, array $columns, array $fields): array
    {
        $value = fn (string $key): string => isset($columns[$key]) ? ($raw['values'][$columns[$key]] ?? '') : '';
        $row = [
            'line' => $raw['line'], 'member_id' => null, 'member_number' => null, 'name' => null, 'field' => null, 'field_label' => $value('field'),
            'type' => null, 'option' => null, 'option_label' => $value('option'), 'starts_on' => null, 'ends_on' => null,
            'note' => $value('note') === '' ? null : $value('note'), 'errors' => [], 'warnings' => [],
        ];
        if (isset($raw['structural_error'])) {
            return [...$row, 'errors' => [$raw['structural_error']]];
        }
        $errors = [];

        $number = $value('member_number');
        $member = preg_match('/^\d{1,9}$/', $number) === 1 ? Member::query()->where('member_number', (int) $number)->first(['id', 'member_number', 'first_name', 'middle_name', 'last_name']) : null;
        if ($member === null) {
            $errors[] = $number === '' ? 'Die Mitgliedsnummer fehlt.' : 'Kein Mitglied mit der Nummer „'.$number.'“.';
        } else {
            $row = [...$row, 'member_id' => $member->id, 'member_number' => $member->member_number, 'name' => AssignmentReports::name($member)];
        }

        $field = collect($fields)->first(fn (array $field): bool => mb_strtolower($field['key']) === mb_strtolower($value('field')) || mb_strtolower($field['label']) === mb_strtolower($value('field')));
        if ($field === null) {
            $errors[] = 'Unbekanntes Feld „'.$value('field').'“. Erlaubt sind aktive Felder für Abteilungen, Ämter und Ehrungen.';
        } else {
            $row = [...$row, 'field' => $field['key'], 'field_label' => $field['label'], 'type' => $field['type']];
            $option = null;
            foreach ($field['activeOptions'] as $optionValue => $label) {
                if (mb_strtolower((string) $optionValue) === mb_strtolower($value('option')) || mb_strtolower((string) $label) === mb_strtolower($value('option'))) {
                    $option = (string) $optionValue;
                    $row['option_label'] = (string) $label;
                    break;
                }
            }
            $option === null ? $errors[] = '„'.$value('option').'“ ist keine aktive Auswahl von „'.$field['label'].'“.' : $row['option'] = $option;
        }

        foreach (['starts_on' => 'Von', 'ends_on' => 'Bis'] as $key => $label) {
            if ($value($key) === '') {
                continue;
            }
            $date = $this->date($value($key));
            $date === null ? $errors[] = '„'.$label.'“ ist kein gültiges Datum (TT.MM.JJJJ oder JJJJ-MM-TT).' : $row[$key] = $date;
        }
        if ($row['note'] !== null && mb_strlen($row['note']) > 255) {
            $errors[] = 'Die Notiz ist zu lang (maximal 255 Zeichen).';
        }

        return [...$row, 'errors' => $errors];
    }

    private function date(string $value): ?string
    {
        foreach (['Y-m-d', 'd.m.Y'] as $format) {
            // Round trip rejects overflowing dates such as 31.02.
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * Applies all rows without errors and rolls everything back. Rows are
     * checked in file order, so conflicts within the file show up as well.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function dryRun(array $rows, User $user): array
    {
        DB::beginTransaction();
        try {
            foreach ($rows as $index => $row) {
                if ($row['errors'] !== []) {
                    continue;
                }
                try {
                    $rows[$index]['warnings'] = $this->add($row, $user);
                } catch (ValidationException $exception) {
                    $rows[$index]['errors'] = array_values(array_unique(collect($exception->errors())->flatten()->all()));
                }
            }
        } catch (Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
        DB::rollBack();

        return $rows;
    }

    /**
     * @param  array<mixed>  $row
     * @return list<string> warnings
     */
    private function add(array $row, User $user): array
    {
        $member = Member::query()->whereKey($row['member_id'])->firstOrFail();

        return $this->assignments->add($member, $user, $member->lock_version, (string) $row['field'], [
            'option' => $row['option'], 'starts_on' => $row['starts_on'], 'ends_on' => $row['ends_on'], 'note' => $row['note'],
        ], 'import');
    }

    private function cacheKey(string $token): string
    {
        if (! preg_match('/^[A-Za-z0-9]{48}$/', $token)) {
            throw new RuntimeException('Ungültiger Import-Schlüssel.');
        }

        return 'assignment-import:'.$token;
    }
}
