<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Terminliste {{ $focus->translatedFormat('F Y') }}</title>
    @include('payments.report-styles')
</head>
<body>
    <div class="report-header">
        <div class="report-heading">
            <h1>{{ $club['name'] ?? config('app.name') }} · Terminliste</h1>
            <p>{{ $focus->locale('de')->translatedFormat('F Y') }} · {{ $events->count() }} Termine</p>
        </div>
        @if ($logo)
            <div class="report-brand"><img class="report-logo" src="{{ $logo }}" alt=""></div>
        @endif
    </div>

    <p>
        Kalender: {{ $calendars->pluck('name')->join(', ') ?: 'Keine ausgewählt' }}
        · Stand {{ $printedAt->format('d.m.Y H:i T') }}
    </p>

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
</body>
</html>
