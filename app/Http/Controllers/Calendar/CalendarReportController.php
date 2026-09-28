<?php

declare(strict_types=1);

namespace App\Http\Controllers\Calendar;

use App\Calendar\CalendarEntries;
use App\Configuration\ClubSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Calendar\CalendarReportRequest;
use App\Members\MemberReportWriter;
use App\Models\ClubCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CalendarReportController extends Controller
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    public function __invoke(CalendarReportRequest $request, CalendarEntries $entries): Response
    {
        $focus = $request->month();
        $layout = $request->layout();
        $calendarIds = $request->calendarIds();
        $calendars = ClubCalendar::query()
            ->when($calendarIds !== null, fn ($query) => $query->whereIn('id', $calendarIds))
            ->orderBy('id')
            ->get();
        if ($layout === 'list') {
            $from = $request->from();
            $until = $request->until();
            $events = $entries->between($calendars, $from, $until->addDay());
            $days = collect();
            $filename = 'terminliste-'.$from->format('Y-m-d').'-bis-'.$until->format('Y-m-d').'.pdf';
        } else {
            $from = $focus->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY);
            $until = $focus->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);
            $events = $entries->between($calendars, $from, $until->addDay());
            $days = $this->monthDays($from, $events);
            $filename = 'monatskalender-'.$focus->format('Y-m').'.pdf';
        }
        if ($events->count() > 5000) {
            throw ValidationException::withMessages([
                'scope' => 'Die Terminliste ist auf 5.000 Einträge begrenzt. Bitte weniger Kalender auswählen.',
            ]);
        }

        $club = $this->clubSettings->data();
        $logo = $this->clubSettings->logoDataUri();
        $printedAt = now()->setTimezone(config('app.display_timezone'));
        $html = view('calendar.report', compact('layout', 'events', 'days', 'calendars', 'focus', 'from', 'until', 'club', 'logo', 'printedAt'))->render();

        return response(MemberReportWriter::pdf($html), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $events
     * @return Collection<int, array{date: CarbonImmutable, events: Collection<int, array<string, mixed>>}>
     */
    private function monthDays(CarbonImmutable $from, Collection $events): Collection
    {
        return collect(range(0, 41))->map(function (int $offset) use ($from, $events): array {
            $date = $from->addDays($offset);
            $next = $date->addDay();

            return [
                'date' => $date,
                'events' => $events->filter(fn (array $event): bool => CarbonImmutable::parse($event['starts_at'])->lt($next)
                    && CarbonImmutable::parse($event['ends_at'])->gt($date))->values(),
            ];
        });
    }
}
