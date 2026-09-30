<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Members\MemberFieldRule;
use App\Models\ClubCalendar;
use App\Models\Member;
use Illuminate\Support\Collection;

final class CalendarAccess
{
    /** @return Collection<int, ClubCalendar> */
    public function forMember(Member $member): Collection
    {
        $rules = new MemberFieldRule;

        return ClubCalendar::query()->with('rules')->orderBy('id')->get()
            ->filter(fn (ClubCalendar $calendar): bool => $calendar->rules->contains(
                fn ($rule): bool => $rules->matches($member, $rule->field_key, $rule->value)
            ))->values();
    }
}
