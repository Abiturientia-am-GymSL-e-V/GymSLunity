<?php

declare(strict_types=1);

namespace App\Members;

use App\Support\Iban;

final class MemberReportValue
{
    /** @param array<string, mixed> $field */
    public static function format(mixed $value, array $field = []): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_array($value)) {
            return self::list($value, $field);
        }
        if (is_bool($value)) {
            return $value ? 'Ja' : 'Nein';
        }
        if (isset($field['options'][(string) $value])) {
            return $field['options'][(string) $value];
        }
        if (($field['type'] ?? '') === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value)) {
            return implode('.', array_reverse(explode('-', substr((string) $value, 0, 10))));
        }
        if (($field['type'] ?? '') === 'decimal') {
            return number_format((float) $value, 2, ',', '.').(($field['key'] ?? '') === 'sponsor_contribution' ? ' €' : '');
        }
        if (($field['key'] ?? '') === 'iban') {
            return Iban::format((string) $value);
        }

        return (string) $value;
    }

    /**
     * Current option values of a department, office or honor field in option
     * order, or assignment lists from the member history with their periods.
     *
     * @param  array<mixed>  $values
     * @param  array<string, mixed>  $field
     */
    private static function list(array $values, array $field): string
    {
        $options = is_array($field['options'] ?? null) ? $field['options'] : [];
        $label = fn (mixed $value): string => (string) ($options[(string) $value] ?? $value);
        $history = array_filter($values, 'is_array');
        if ($history !== []) {
            return implode('; ', array_map(fn (array $assignment): string => $label($assignment['option'] ?? '')
                .' ('.self::period($assignment['starts_on'] ?? null, $assignment['ends_on'] ?? null, ($field['type'] ?? '') === 'honor').')'
                .(is_string($assignment['note'] ?? null) && $assignment['note'] !== '' ? ' – '.$assignment['note'] : ''), $history));
        }
        // Rank order: the position of the option in the field configuration.
        $rank = array_flip(array_map('strval', array_keys($options)));
        $current = array_values(array_unique(array_map('strval', array_filter($values, 'is_scalar'))));
        usort($current, fn (string $a, string $b): int => [$rank[$a] ?? PHP_INT_MAX, $a] <=> [$rank[$b] ?? PHP_INT_MAX, $b]);

        return implode(', ', array_map($label, $current));
    }

    public static function period(?string $start, ?string $end, bool $honor): string
    {
        $day = fn (string $date): string => self::format($date, ['type' => 'date']);
        if ($honor) {
            return $start ? 'am '.$day($start) : 'Datum unbekannt';
        }

        return match (true) {
            $start !== null && $end !== null => $day($start).' – '.$day($end),
            $end !== null => 'Beginn unbekannt – '.$day($end),
            $start !== null => 'seit '.$day($start),
            default => 'Beginn unbekannt',
        };
    }
}
