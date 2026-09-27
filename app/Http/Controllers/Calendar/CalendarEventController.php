<?php

namespace App\Http\Controllers\Calendar;

use App\Http\Controllers\Controller;
use App\Models\ClubCalendar;
use App\Models\ClubCalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CalendarEventController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $calendar = ClubCalendar::query()->findOrFail($request->integer('calendar_id'));
        abort_if($calendar->type === 'birthdays', 422, 'Geburtstage werden automatisch aus den Mitgliedsdaten erzeugt.');
        $data = $this->validated($request);
        ClubCalendarEvent::query()->create([...$data, 'club_calendar_id' => $calendar->id, 'created_by' => $request->user()->id]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Der Termin wurde angelegt.']);

        return back();
    }

    public function update(Request $request, ClubCalendarEvent $event): RedirectResponse
    {
        $calendar = ClubCalendar::query()->findOrFail($request->integer('calendar_id'));
        abort_if($calendar->type === 'birthdays', 422);
        $event->update([...$this->validated($request), 'club_calendar_id' => $calendar->id]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Der Termin wurde gespeichert.']);

        return back();
    }

    public function destroy(ClubCalendarEvent $event): RedirectResponse
    {
        $event->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Der Termin wurde gelöscht.']);

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'calendar_id' => ['required', 'integer', 'exists:club_calendars,id'], 'title' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'], 'ends_at' => ['required', 'date_format:Y-m-d\TH:i'], 'all_day' => ['required', 'boolean'],
        ]);
        $start = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['starts_at'], config('app.timezone'));
        $end = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['ends_at'], config('app.timezone'));
        if ($data['all_day']) {
            $start = $start->startOfDay();
            $end = $end->startOfDay()->addDay();
        }
        if ($end->lte($start)) {
            throw ValidationException::withMessages(['ends_at' => 'Das Ende muss nach dem Beginn liegen.']);
        }

        return ['title' => $data['title'], 'location' => $data['location'] ?: null, 'description' => $data['description'] ?: null, 'starts_at' => $start, 'ends_at' => $end, 'all_day' => $data['all_day']];
    }
}
