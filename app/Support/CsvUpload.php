<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Reads an uploaded CSV file for the imports: UTF-8, UTF-16 or
 * Windows-1252, separated by semicolon, comma or tab, with a header row.
 * Rows whose number of values differs from the header are kept with a
 * structural error, empty lines are skipped.
 */
final class CsvUpload
{
    /**
     * @return array{headers: list<string>, delimiter: string, rows: list<array{line: int, values: array<string, string>, structural_error?: string}>}
     */
    public static function read(UploadedFile $file, int $maxRows, int $maxColumns): array
    {
        $content = self::utf8($file->getContent());
        $delimiter = self::delimiter($content);
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
            if (in_array('', $headers, true) || count($headers) !== count(array_unique($headers))) {
                throw ValidationException::withMessages(['csv' => 'Die Kopfzeile enthält leere oder doppelte Spaltennamen.']);
            }
            if (count($headers) > $maxColumns) {
                throw ValidationException::withMessages(['csv' => 'Die CSV-Datei darf höchstens '.$maxColumns.' Spalten enthalten.']);
            }
            $rows = [];
            $line = 1;
            while (($cells = fgetcsv($handle, separator: $delimiter, escape: '')) !== false) {
                $line++;
                if (count($rows) >= $maxRows) {
                    throw ValidationException::withMessages(['csv' => 'Pro Import sind höchstens '.$maxRows.' Datenzeilen erlaubt.']);
                }
                if (count($cells) === 1 && trim((string) $cells[0]) === '') {
                    continue;
                }
                if (count($cells) !== count($headers)) {
                    $rows[] = ['line' => $line, 'values' => [], 'structural_error' => 'Die Anzahl der Werte stimmt nicht mit der Kopfzeile überein.'];

                    continue;
                }
                $rows[] = ['line' => $line, 'values' => array_combine($headers, array_map(fn (mixed $value): string => trim((string) $value), $cells))];
            }
        } finally {
            fclose($handle);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['csv' => 'Die CSV-Datei enthält keine Datenzeilen.']);
        }

        return ['headers' => $headers, 'delimiter' => $delimiter, 'rows' => $rows];
    }

    /** Lower case ASCII form of a header for alias matching. */
    public static function normalizeHeader(string $header): string
    {
        $header = strtr(mb_strtolower(trim($header)), [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        ]);

        return preg_replace('/[^a-z0-9]+/', '', $header) ?? '';
    }

    private static function utf8(string $content): string
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

    private static function delimiter(string $content): string
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
}
