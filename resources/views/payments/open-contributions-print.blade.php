<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Offene Beiträge</title>
    @include('payments.report-styles')
</head>
<body>
    <div class="toolbar"><button type="button" onclick="window.print()">Drucken</button> · {{ count($rows) }} Mitglieder</div>
    <div class="report-header">
        <div class="report-heading"><h1>{{ $club['name'] ?? config('app.name') }} · Offene Beiträge</h1></div>
        @if ($logo)<div class="report-brand"><img class="report-logo" src="{{ $logo }}" alt="Vereinslogo"></div>@endif
    </div>
    <p>Stand {{ $printedAt->format('d.m.Y H:i T') }} · {{ count($rows) }} Mitglieder</p>
    <table>
        <thead><tr><th>Mitglied</th><th>E-Mail</th><th>Älteste Fälligkeit</th><th>Posten</th><th class="number">Überfällig</th><th class="number">Offen</th></tr></thead>
        <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>{{ $row['member_name'] }}<br>Nr. {{ $row['member_number'] }}</td>
                <td>{{ $row['email'] ?: '–' }}</td>
                <td>{{ $row['earliest_due_date'] ? \Carbon\CarbonImmutable::parse($row['earliest_due_date'])->format('d.m.Y') : '–' }}</td>
                <td>{{ $row['open_count'] }}</td>
                <td class="number">{{ number_format($row['overdue_cents'] / 100, 2, ',', '.') }} €</td>
                <td class="number">{{ number_format($row['open_cents'] / 100, 2, ',', '.') }} €</td>
            </tr>
        @empty
            <tr><td colspan="6">Keine offenen Beiträge.</td></tr>
        @endforelse
        </tbody>
    </table>
</body>
</html>
