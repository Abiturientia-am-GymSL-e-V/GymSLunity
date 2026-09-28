<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Models\ClubCalendar;
use App\Models\ClubCalendarEvent;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class CalendarEntries
{
    /** @param Collection<int, ClubCalendar> $calendars
     * @return Collection<int, array<string, mixed>>
     */
    public function between(Collection $calendars, CarbonImmutable $from, CarbonImmutable $until): Collection
    {
        $ids = $calendars->pluck('id');
        $events = ClubCalendarEvent::query()->with('calendar')->whereIn('club_calendar_id', $ids)
            ->where('starts_at', '<', $until)->where('ends_at', '>', $from)
            ->orderBy('starts_at')->get()->map(fn (ClubCalendarEvent $event): array => $this->eventEntry($event));

        $birthday = $calendars->firstWhere('type', 'birthdays');
        if ($birthday) {
            for ($year = $from->year; $year <= $until->year; $year++) {
                Member::query()->whereNotNull('birth_date')
                    ->where(fn ($query) => $query->whereNull('joined_at')->orWhereDate('joined_at', '<', $until->toDateString()))
                    ->where(fn ($query) => $query->whereNull('left_at')->orWhereDate('left_at', '>=', $from->toDateString()))
                    ->where(fn ($query) => $query->whereNull('deceased_at')->orWhereDate('deceased_at', '>=', $from->toDateString()))
                    ->get(['id', 'member_number', 'first_name', 'middle_name', 'last_name', 'birth_date', 'joined_at', 'left_at', 'deceased_at'])->each(
                        function (Member $member) use (&$events, $birthday, $year, $from, $until): void {
                            $month = CarbonImmutable::create($year, $member->birth_date->month, 1, 0, 0, 0, config('app.timezone'));
                            $day = $month->day(min($member->birth_date->day, $month->daysInMonth));
                            if ($day->lt($from) || $day->gte($until)
                                || ($member->joined_at && $day->lt($member->joined_at))
                                || ($member->left_at && $day->gte($member->left_at))
                                || ($member->deceased_at && $day->gte($member->deceased_at))) {
                                return;
                            }
                            $name = collect([$member->first_name, $member->middle_name, $member->last_name])->filter()->join(' ');
                            $events->push($this->birthdayEntry($member->id, $birthday, $year, $name, $day));
                        }
                    );
            }
        }

        return $events->sortBy('starts_at')->values();
    }

    /** @return array<string, mixed> */
    private function eventEntry(ClubCalendarEvent $event): array
    {
        return [
            'id' => 'event-'.$event->id, 'event_id' => $event->id, 'calendar_id' => $event->club_calendar_id,
            'calendar_name' => $event->calendar->name, 'color' => $event->calendar->color, 'title' => $event->title,
            'location' => $event->location, 'description' => $event->description,
            'starts_at' => $event->starts_at->format('Y-m-d\TH:i:s'), 'ends_at' => $event->ends_at->format('Y-m-d\TH:i:s'),
            'all_day' => $event->all_day, 'editable' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function birthdayEntry(int $memberId, ClubCalendar $calendar, int $year, string $name, CarbonImmutable $day): array
    {
        return [
            'id' => 'birthday-'.$memberId.'-'.$year, 'event_id' => null, 'calendar_id' => $calendar->id,
            'calendar_name' => $calendar->name, 'color' => $calendar->color, 'title' => 'Geburtstag: '.$name,
            'location' => null, 'description' => null, 'starts_at' => $day->format('Y-m-d\T00:00:00'),
            'ends_at' => $day->addDay()->format('Y-m-d\T00:00:00'), 'all_day' => true, 'editable' => false,
        ];
    }
}
