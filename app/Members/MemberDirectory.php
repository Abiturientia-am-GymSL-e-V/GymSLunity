<?php

declare(strict_types=1);

namespace App\Members;

use App\Models\Member;
use App\Models\MemberFieldDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class MemberDirectory
{
    /** @param array<string, mixed> $filters
     * @return Builder<Member>
     */
    public static function query(array $filters): Builder
    {
        $query = Member::query();

        // Bind all search terms; treat LIKE wildcards as literal input.
        foreach (preg_split('/\s+/u', $filters['q'], flags: PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $query->where(function (Builder $search) use ($pattern) {
                foreach (['member_number', 'first_name', 'middle_name', 'last_name', 'email', 'mobile_phone', 'street', 'postal_code', 'city'] as $column) {
                    $search->orWhereRaw("$column LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }

        $customFields = MemberFieldDefinition::query()->where('is_active', true)->where('is_custom', true)->get()->keyBy('key');
        foreach ($filters['custom'] as $key => $value) {
            $type = $customFields->get($key)?->type;
            $query->where('custom_values->'.$key, match ($type) {
                'number' => (int) $value, 'boolean' => $value === '1', 'decimal' => number_format((float) $value, 2, '.', ''), default => $value,
            });
        }
        if ($filters['membership'] !== '') {
            $query->where('membership_type', $filters['membership']);
        }
        foreach (['department_role', 'club_role'] as $column) {
            $value = $filters[$column];
            if ($value === '__none__') {
                $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, ''));
            } elseif ($value === '__any__') {
                $query->whereNotNull($column)->where($column, '<>', '');
            } elseif ($value !== '') {
                $query->where($column, $value);
            }
        }

        if ($filters['sort'] === 'name') {
            $query->orderBy('last_name', $filters['direction'])->orderBy('first_name', $filters['direction']);
        } elseif (isset($customFields[$filters['sort']])) {
            $field = $customFields[$filters['sort']];
            if (in_array($field->type, ['number', 'decimal'], true)) {
                $sql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
                    ? ($filters['direction'] === 'desc' ? 'CAST(JSON_UNQUOTE(JSON_EXTRACT(custom_values, ?)) AS DECIMAL(20, 2)) DESC' : 'CAST(JSON_UNQUOTE(JSON_EXTRACT(custom_values, ?)) AS DECIMAL(20, 2)) ASC')
                    : ($filters['direction'] === 'desc' ? 'CAST(json_extract(custom_values, ?) AS REAL) DESC' : 'CAST(json_extract(custom_values, ?) AS REAL) ASC');
                $query->orderByRaw($sql, ['$."'.$field->key.'"']);
            } else {
                $query->orderBy('custom_values->'.$field->key, $filters['direction']);
            }
        } else {
            $query->orderBy($filters['sort'], $filters['direction']);
        }
        // Deterministic order across pages, including duplicate names and NULLs.
        $query->orderBy('id');

        return $query;
    }
}
