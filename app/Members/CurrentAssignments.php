<?php

declare(strict_types=1);

namespace App\Members;

use App\Models\Member;
use App\Models\MemberAssignment;
use App\Support\Clock;
use Illuminate\Database\Eloquent\Builder;

/**
 * Department, office and honor fields in lists, filters, exports and
 * placeholders. There they always stand for the assignments valid today.
 *
 * Filter values: an option value means "currently holds it", ANY and NONE
 * mean "currently any" or "currently none", EVER means "at any time any"
 * and EVER_PREFIX plus an option value "at any time this option".
 */
final class CurrentAssignments
{
    public const ANY = '__any__';

    public const NONE = '__none__';

    public const EVER = '__ever__';

    public const EVER_PREFIX = '__ever__:';

    /**
     * Current option values per field key. Uses the eager loaded
     * currentAssignments relation when present.
     *
     * @return array<string, list<string>>
     */
    public static function values(Member $member): array
    {
        $values = [];
        $assignments = $member->relationLoaded('currentAssignments') ? $member->currentAssignments : $member->currentAssignments()->get();
        foreach ($assignments as $assignment) {
            $values[$assignment->field_key][] = $assignment->option_value;
        }

        return array_map(fn (array $options): array => array_values(array_unique($options)), $values);
    }

    /**
     * Every filter value that matches the member per field key, for filters
     * evaluated in the browser. Uses the eager loaded assignments relation
     * when present.
     *
     * @return array<string, list<string>>
     */
    public static function filterTokens(Member $member): array
    {
        $today = Clock::todayString();
        $tokens = [];
        $assignments = $member->relationLoaded('assignments') ? $member->assignments : $member->assignments()->get();
        foreach ($assignments as $assignment) {
            $tokens[$assignment->field_key][] = self::EVER;
            $tokens[$assignment->field_key][] = self::EVER_PREFIX.$assignment->option_value;
            if ($assignment->isActiveOn($today)) {
                $tokens[$assignment->field_key][] = self::ANY;
                $tokens[$assignment->field_key][] = $assignment->option_value;
            }
        }
        $tokens = array_map(fn (array $values): array => array_values(array_unique($values)), $tokens);
        foreach ($tokens as $key => $values) {
            if (! in_array(self::ANY, $values, true)) {
                $tokens[$key][] = self::NONE;
            }
        }

        return $tokens;
    }

    /**
     * Filters members by one department, office or honor field.
     *
     * @param  Builder<Member>  $query
     */
    public static function filter(Builder $query, string $fieldKey, string $value): void
    {
        if ($value === '') {
            return;
        }
        $ever = $value === self::EVER || str_starts_with($value, self::EVER_PREFIX);
        $option = match (true) {
            $value === self::EVER, $value === self::ANY, $value === self::NONE => null,
            $ever => substr($value, strlen(self::EVER_PREFIX)),
            default => $value,
        };
        $constraint = function (Builder $assignments) use ($fieldKey, $option, $ever): void {
            /** @var Builder<MemberAssignment> $assignments */
            $assignments->where('field_key', $fieldKey)->when($option !== null, fn (Builder $query) => $query->where('option_value', $option));
            if (! $ever) {
                $assignments->activeOn(Clock::today());
            }
        };
        $value === self::NONE ? $query->whereDoesntHave('assignments', $constraint) : $query->whereHas('assignments', $constraint);
    }
}
