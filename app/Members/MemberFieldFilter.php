<?php

declare(strict_types=1);

namespace App\Members;

use App\Models\Member;
use App\Models\MemberFieldDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applies filters chosen field by field (as in the signature lists) on the
 * server. Core fields are member columns, custom fields live in the
 * custom_values JSON column, department, office and honor fields in
 * member_assignments. Unknown keys never reach the query.
 */
final class MemberFieldFilter
{
    /** Fields that are encrypted or only meaningful for SEPA handling. */
    public const EXCLUDED = [
        'iban', 'mandate_reference', 'mandate_signed_at', 'mandate_type', 'account_holder_first_name',
        'account_holder_last_name', 'account_holder_street', 'account_holder_postal_code',
        'account_holder_city', 'account_holder_country',
    ];

    /** Value for "field has any value" and "field is empty". */
    public const ANY = '__any__';

    public const NONE = '__none__';

    /** @return list<array<string, mixed>> filterable directory fields */
    public static function fields(): array
    {
        return array_values(array_filter(
            MemberFields::directoryFields(),
            fn (array $field): bool => ! in_array($field['key'], self::EXCLUDED, true)
                && (! $field['custom'] || $field['filterable']),
        ));
    }

    /**
     * @param  Builder<Member>  $query
     * @param  list<array{key: string, value: string, value_to: string}>  $filters
     */
    public static function apply(Builder $query, array $filters): void
    {
        $fields = collect(self::fields())->keyBy('key');
        foreach ($filters as $filter) {
            $field = $fields->get($filter['key']);
            if ($field === null) {
                continue;
            }
            $column = $field['custom'] ? 'custom_values->'.$field['key'] : $field['key'];
            $value = trim($filter['value']);
            $to = trim($filter['value_to']);
            if (in_array($field['type'], MemberFieldDefinition::TEMPORAL_TYPES, true)) {
                CurrentAssignments::filter($query, $field['key'], $value);

                continue;
            }

            $textual = in_array($field['type'], ['text', 'email', 'tel', 'select'], true);
            if ($value === self::NONE) {
                $query->where(fn (Builder $empty) => $textual ? $empty->whereNull($column)->orWhere($column, '') : $empty->whereNull($column));

                continue;
            }
            if ($value === self::ANY) {
                $textual ? $query->whereNotNull($column)->where($column, '<>', '') : $query->whereNotNull($column);

                continue;
            }
            match ($field['type']) {
                'date' => self::range($query, $column, $value, $to, true),
                'number', 'decimal' => self::range($query, $column, $value, $to, false),
                'boolean' => $value === '' ? null : ($value === '1'
                    ? $query->where($column, true)
                    : $query->where(fn (Builder $no) => $no->whereNull($column)->orWhere($column, false))),
                'select' => $value === '' ? null : $query->where($column, $value),
                // MySQL/MariaDB treat the backslash as LIKE escape character. The
                // column is taken from the field whitelist above.
                default => $value === '' ? null : $query->where($column, 'like', '%'.addcslashes($value, '\\%_').'%'),
            };
        }
    }

    /** @param Builder<Member> $query */
    private static function range(Builder $query, string $column, string $from, string $to, bool $date): void
    {
        if ($from !== '') {
            $date ? $query->whereDate($column, '>=', $from) : $query->where($column, '>=', self::number($from));
        }
        if ($to !== '') {
            $date ? $query->whereDate($column, '<=', $to) : $query->where($column, '<=', self::number($to));
        }
    }

    /**
     * Whole numbers are bound as integers: PDO passes floats to SQLite as
     * text, which never compares as greater than a JSON number.
     */
    private static function number(string $value): int|float
    {
        return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : (float) $value;
    }
}
