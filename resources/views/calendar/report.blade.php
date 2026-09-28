<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ $layout === 'month' ? 'Monatskalender '.$focus->locale('de')->translatedFormat('F Y') : 'Terminliste' }}</title>
    @include('payments.report-styles')
    <style>
        @page { size: A4 {{ $layout === 'month' ? 'landscape' : 'portrait' }}; margin: 10mm; }
        body { font-size: 8px; }
        .calendar-grid { border-collapse: collapse; table-layout: fixed; width: 100%; }
        .calendar-grid th { padding: 4px; text-align: center; }
        .calendar-grid td { height: 31mm; padding: 3px; }
        .calendar-grid .outside { background: #f7f8fa; color: #748394; }
        .day-number { font-weight: bold; margin-bottom: 3px; }
        .calendar-event { border-left: 2px solid #526577; margin: 0 0 3px; padding-left: 3px; line-height: 1.2; }
        .calendar-event-time { color: #526577; font-size: 7px; }
    </style>
</head>
<body>
    <div class="report-header">
        <div class="report-heading">
            <h1>
                {{ $club['name'] ?? config('app.name') }} ·
                {{ $layout === 'month' ? 'Monatskalender' : 'Terminliste' }}
            </h1>
            <p>
                @if ($layout === 'month')
                    {{ $focus->locale('de')->translatedFormat('F Y') }}
                @else
                    {{ $from->format('d.m.Y') }}–{{ $until->format('d.m.Y') }}
                @endif
                · {{ $events->count() }} Termine
            </p>
        </div>
        @if ($logo)
            <div class="report-brand"><img class="report-logo" src="{{ $logo }}" alt=""></div>
        @endif
    </div>

    <p>
        Kalender: {{ $calendars->pluck('name')->join(', ') ?: 'Keine ausgewählt' }}
        · Stand {{ $printedAt->format('d.m.Y H:i T') }}
    </p>

    @if ($layout === 'month')
        <table class="calendar-grid">
            <thead>
                <tr>
                    @foreach (['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'] as $weekday)
                        <th>{{ $weekday }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($days->chunk(7) as $week)
                    <tr>
                        @foreach ($week as $day)
                            <td class="{{ $day['date']->month === $focus->month ? '' : 'outside' }}">
                                <div class="day-number">{{ $day['date']->format('d.m.') }}</div>
                                @foreach ($day['events']->take(4) as $event)
                                    <div class="calendar-event" style="border-color: {{ $event['color'] }}">
                                        @unless ($event['all_day'])
                                            <span class="calendar-event-time">{{ substr($event['starts_at'], 11, 5) }}</span>
                                        @endunless
                                        {{ $event['title'] }}
                                    </div>
                                @endforeach
                                @if ($day['events']->count() > 4)
                                    <div>+ {{ $day['events']->count() - 4 }} weitere</div>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <table>
            <thead>
                <tr>
                    <th style="width: 18%">Datum</th>
                    <th style="width: 11%">Zeit</th>
                    <th style="width: 24%">Termin</th>
                    <th style="width: 19%">Kalender</th>
                    <th style="width: 28%">Ort / Beschreibung</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($events as $event)
                    @php
                        $start = \Carbon\CarbonImmutable::parse($event['starts_at']);
                        $end = \Carbon\CarbonImmutable::parse($event['ends_at']);
                        $displayEnd = $event['all_day'] ? $end->subDay() : $end;
                    @endphp
                    <tr>
                        <td>
                            {{ $start->format('d.m.Y') }}
                            @if (! $start->isSameDay($displayEnd))
                                – {{ $displayEnd->format('d.m.Y') }}
                            @endif
                        </td>
                        <td>
                            @if ($event['all_day'])
                                Ganztägig
                            @elseif ($start->isSameDay($end))
                                {{ $start->format('H:i') }}–{{ $end->format('H:i') }}
                            @else
                                {{ $start->format('H:i') }}–{{ $end->format('d.m. H:i') }}
                            @endif
                        </td>
                        <td>{{ $event['title'] }}</td>
                        <td>{{ $event['calendar_name'] }}</td>
                        <td>
                            {{ $event['location'] ?: '–' }}
                            @if ($event['description'])
                                <br>{{ $event['description'] }}
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">Keine Termine im gewählten Zeitraum.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif
</body>
</html>
