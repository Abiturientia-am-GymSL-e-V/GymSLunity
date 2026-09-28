<?php

declare(strict_types=1);

namespace App\Http\Controllers\Calendar;

use App\Http\Controllers\Controller;
use App\Http\Requests\Calendar\CalendarEventRequest;
use App\Models\ClubCalendar;
use App\Models\ClubCalendarEvent;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class CalendarEventController extends Controller
{
    public function store(CalendarEventRequest $request): RedirectResponse
    {
        $calendar = ClubCalendar::query()->findOrFail($request->integer('calendar_id'));
        abort_if($calendar->type === 'birthdays', 422, 'Geburtstage werden automatisch aus den Mitgliedsdaten erzeugt.');
        ClubCalendarEvent::query()->create([...$request->eventAttributes(), 'club_calendar_id' => $calendar->id, 'created_by' => $request->user()->id]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Der Termin wurde angelegt.']);

        return back();
    }

    public function update(CalendarEventRequest $request, ClubCalendarEvent $event): RedirectResponse
    {
        $calendar = ClubCalendar::query()->findOrFail($request->integer('calendar_id'));
        abort_if($calendar->type === 'birthdays', 422);
        $event->update([...$request->eventAttributes(), 'club_calendar_id' => $calendar->id]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Der Termin wurde gespeichert.']);

        return back();
    }

    public function destroy(ClubCalendarEvent $event): RedirectResponse
    {
        $event->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Der Termin wurde gelöscht.']);

        return back();
    }
}
