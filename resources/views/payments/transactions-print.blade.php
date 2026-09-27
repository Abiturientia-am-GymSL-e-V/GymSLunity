<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Kontobuchungen</title>
    @include('payments.report-styles')
</head>
<body>
    <div class="toolbar"><button type="button" onclick="window.print()">Drucken</button> · {{ $entries->count() }} Buchungen</div>
    <div class="report-header">
        <div class="report-heading"><h1>{{ $club['name'] ?? config('app.name') }} · Kontobuchungen</h1></div>
        @if ($logo)<div class="report-brand"><img class="report-logo" src="{{ $logo }}" alt="Vereinslogo"></div>@endif
    </div>
    <p>
        Zeitraum {{ \Carbon\CarbonImmutable::parse($filters['from'])->format('d.m.Y') }}–{{ \Carbon\CarbonImmutable::parse($filters['to'])->format('d.m.Y') }}
        · Stand {{ $printedAt->format('d.m.Y H:i T') }} · {{ $entries->count() }} Buchungen
    </p>
    <table>
        <thead><tr><th>Datum</th><th>Mitglied</th><th>Art</th><th>Beschreibung</th><th class="number">Betrag</th></tr></thead>
        <tbody>
        @forelse ($entries as $entry)
            <tr>
                <td>{{ $entry->booking_date->format('d.m.Y') }}</td>
                <td>{{ $entry->account->member->first_name }} {{ $entry->account->member->last_name }}<br>Nr. {{ $entry->account->member->member_number }}</td>
                <td>{{ $report->kindLabel($entry->kind) }}</td>
                <td>{{ $entry->description }}@if ($entry->reference)<br>{{ $entry->reference }}@endif</td>
                <td class="number">{{ number_format($entry->amount_cents / 100, 2, ',', '.') }} €</td>
            </tr>
        @empty
            <tr><td colspan="5">Keine passenden Kontobuchungen.</td></tr>
        @endforelse
        </tbody>
    </table>
</body>
</html>
