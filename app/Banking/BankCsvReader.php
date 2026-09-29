<?php

declare(strict_types=1);

namespace App\Banking;

use App\Payments\Money;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Reads bank statement CSV exports for both the contribution and the
 * accounting import and matches references in the purpose text.
 */
final class BankCsvReader
{
    public const MAX_ROWS = 5000;

    /** @var list<string> */
    private array $headers;

    /**
     * @param  list<string>  $headers  normalized header names
     * @param  list<list<string>>  $rows
     */
    private function __construct(array $headers, public readonly array $rows)
    {
        $this->headers = $headers;
    }

    public static function fromString(string $contents): self
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw ValidationException::withMessages(['csv' => 'Die CSV-Datei konnte nicht gelesen werden.']);
        }
        try {
            fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents);
            rewind($stream);
            $sample = fgets($stream) ?: '';
            $delimiter = substr_count($sample, ';') >= substr_count($sample, ',') ? ';' : ',';
            rewind($stream);
            $headers = fgetcsv($stream, separator: $delimiter, escape: '');
            if (! is_array($headers)) {
                throw ValidationException::withMessages(['csv' => 'Die CSV-Datei hat keine Kopfzeile.']);
            }
            $rows = [];
            while (($row = fgetcsv($stream, separator: $delimiter, escape: '')) !== false) {
                $row = array_map(fn (?string $value): string => (string) $value, $row);
                if (count(array_filter($row, fn (string $value): bool => trim($value) !== '')) > 0) {
                    $rows[] = array_pad($row, count($headers), '');
                }
                if (count($rows) > self::MAX_ROWS) {
                    throw ValidationException::withMessages(['csv' => 'Pro Import sind höchstens 5.000 Buchungen erlaubt.']);
                }
            }
        } finally {
            fclose($stream);
        }
        $normalized = array_map(fn (?string $header): string => preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim((string) $header))) ?? '', $headers);

        return new self($normalized, $rows);
    }

    /** @param list<string> $names */
    public function column(array $names): ?int
    {
        foreach ($names as $name) {
            $index = array_search($name, $this->headers, true);
            if ($index !== false) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $row
     * @param  list<string>  $names
     */
    public function value(array $row, array $names): string
    {
        $index = $this->column($names);

        return $index === null ? '' : trim($row[$index] ?? '');
    }

    /**
     * Parses German and international notations to cents without floats:
     * "1.234,56", "1,234.56", "-12,50", "12.5" and "1.500" (= 1500 €).
     * Returns 0 for anything that is not a plain amount.
     */
    public static function amount(string $value): int
    {
        $value = preg_replace('/[^0-9,.+-]/', '', $value) ?? '';
        $negative = str_starts_with($value, '-') || str_ends_with($value, '-');
        $value = trim($value, '+-');
        $comma = strrpos($value, ',');
        $dot = strrpos($value, '.');
        if ($comma !== false && $dot !== false) {
            $decimal = $comma > $dot ? ',' : '.';
            $value = str_replace($decimal === ',' ? '.' : ',', '', $value);
        } elseif ($comma !== false || $dot !== false) {
            $separator = $comma !== false ? ',' : '.';
            // Only groups of exactly three digits are thousands separators.
            if (preg_match('/^\d{1,3}(?:'.preg_quote($separator, '/').'\d{3})+$/', $value)) {
                $value = str_replace($separator, '', $value);
            }
        }
        try {
            $cents = Money::cents(str_replace(',', '.', $value));
        } catch (InvalidArgumentException) {
            return 0;
        }

        return $negative ? -$cents : $cents;
    }

    public static function date(string $value): ?string
    {
        $value = trim($value);
        foreach (['!Y-m-d', '!d.m.Y', '!d/m/Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date && $date->format(substr($format, 1)) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * True when the token appears as a whole word, so "RW-2026-000001"
     * does not match inside "RW-2026-0000012".
     */
    public static function containsToken(string $text, string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        return preg_match('/(?<![\p{L}\p{N}])'.preg_quote(mb_strtoupper($token), '/').'(?![\p{L}\p{N}])/u', mb_strtoupper($text)) === 1;
    }

    /**
     * Returns the only candidate whose tokens appear in the text. Several
     * matches are ambiguous and return null, so the row is left for manual
     * assignment instead of being booked to the wrong account.
     *
     * @template T
     *
     * @param  iterable<T>  $candidates
     * @param  callable(T): list<string|null>  $tokens
     * @return T|null
     */
    public static function uniqueMatch(string $text, iterable $candidates, callable $tokens): mixed
    {
        $found = null;
        foreach ($candidates as $candidate) {
            foreach ($tokens($candidate) as $token) {
                if (is_string($token) && self::containsToken($text, $token)) {
                    if ($found !== null && $found !== $candidate) {
                        return null;
                    }
                    $found = $candidate;
                    break;
                }
            }
        }

        return $found;
    }
}
