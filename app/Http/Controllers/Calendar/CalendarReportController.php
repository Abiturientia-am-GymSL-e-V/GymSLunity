<?php

declare(strict_types=1);

namespace App\Http\Controllers\Calendar;

use App\Calendar\CalendarEntries;
use App\Configuration\ClubSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Calendar\CalendarReportRequest;
use App\Members\MemberReportWriter;
use App\Models\ClubCalendar;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class CalendarReportController extends Controller
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    public function __invoke(CalendarReportRequest $request, CalendarEntries $entries): Response
    {
        $focus = $request->month();
        $calendarIds = $request->calendarIds();
        $calendars = ClubCalendar::query()
            ->when($calendarIds !== null, fn ($query) => $query->whereIn('id', $calendarIds))
            ->orderBy('id')
            ->get();
        $events = $entries->between($calendars, $focus->startOfMonth(), $focus->addMonth()->startOfMonth());
        if ($events->count() > 5000) {
            throw ValidationException::withMessages([
                'scope' => 'Die Terminliste ist auf 5.000 Einträge begrenzt. Bitte weniger Kalender auswählen.',
            ]);
        }

        $club = $this->clubSettings->data();
        $logo = $this->clubSettings->logoDataUri();
        $printedAt = now()->setTimezone(config('app.display_timezone'));
        $html = view('calendar.report', compact('events', 'calendars', 'focus', 'club', 'logo', 'printedAt'))->render();

        return response(MemberReportWriter::pdf($html, true), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="terminliste-'.$focus->format('Y-m').'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
