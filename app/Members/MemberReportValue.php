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
}
