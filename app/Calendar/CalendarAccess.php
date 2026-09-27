<?php

namespace App\Calendar;

use App\Models\ClubCalendar;
use App\Models\Member;
use Illuminate\Support\Collection;

final class CalendarAccess
{
    /** @return Collection<int, ClubCalendar> */
    public function forMember(Member $member): Collection
    {
        return ClubCalendar::query()->with('rules')->orderBy('id')->get()
            ->filter(fn (ClubCalendar $calendar): bool => $calendar->rules->contains(
                fn ($rule): bool => $this->matches($member, $rule->field_key, $rule->value)
            ))->values();
    }

    private function matches(Member $member, string $field, string $expected): bool
    {
        $value = str_starts_with($field, 'custom_')
            ? ($member->custom_values[$field] ?? null)
            : $member->getAttribute($field);

        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        return (string) $value === $expected;
    }
}
