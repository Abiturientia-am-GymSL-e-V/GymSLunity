<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Auswertungsbericht</title>
    @include('payments.report-styles')
    <style>
        @page { margin: 11mm; }
        body { font-size: 8.5px; }
        h2 { margin: 14px 0 6px; font-size: 14px; }
        h3 { margin: 10px 0 5px; font-size: 11px; }
        .meta { margin: 0 0 12px; }
        .page { page-break-before: always; }
        .keep { page-break-inside: avoid; }
        .summary { margin-bottom: 10px; table-layout: auto; }
        .summary td { width: 20%; text-align: center; }
        .summary strong { display: block; margin-top: 3px; font-size: 15px; }
        .muted { color: #526577; }
        .compact th, .compact td { padding: 4px; }
        .breakdown { table-layout: auto; }
        .breakdown td:first-child { width: 70%; }
        .footer { margin-top: 12px; border-top: 1px solid #d5dde5; padding-top: 5px; color: #526577; font-size: 7.5px; }
    </style>
</head>
<body>
@php
    $number = fn (int $value): string => number_format($value, 0, ',', '.');
    $money = fn (int $cents): string => number_format($cents / 100, 2, ',', '.').' €';
    $stockTotals = [
        'female' => collect($stockReport)->sum('female'),
        'male' => collect($stockReport)->sum('male'),
        'diverse' => collect($stockReport)->sum('diverse'),
        'unspecified' => collect($stockReport)->sum('unspecified'),
        'total' => collect($stockReport)->sum('total'),
    ];
@endphp

<header class="report-header">
    <div class="report-heading">
        <h1>Auswertungsbericht</h1>
        <p class="meta">
            Zeitraum {{ $from->format('d.m.Y') }} bis {{ $to->format('d.m.Y') }} ·
            Mitgliederbestand zum {{ $asOf->format('d.m.Y') }}
        </p>
    </div>
    <div class="report-brand">
        @if ($logo)<img class="report-logo" src="{{ $logo }}" alt="Vereinslogo">@endif
        <div>{{ $club['short_name'] ?? $club['name'] ?? config('app.name') }}</div>
    </div>
</header>

<h2>Übersicht</h2>
<table class="summary keep">
    <tr>
        <td><span class="muted">Aktive Mitglieder</span><strong>{{ $number($summary['active_members']) }}</strong></td>
        <td><span class="muted">Kontakte</span><strong>{{ $number($summary['contacts']) }}</strong></td>
        <td><span class="muted">Eintritte</span><strong>{{ $number($summary['joined']) }}</strong></td>
        <td><span class="muted">Abgänge</span><strong>{{ $number($summary['departed']) }}</strong></td>
        <td><span class="muted">Nettoentwicklung</span><strong>{{ $summary['net_change'] > 0 ? '+' : '' }}{{ $number($summary['net_change']) }}</strong></td>
    </tr>
</table>

<h3>Mitgliederentwicklung</h3>
<table class="compact">
    <thead><tr><th>Monat</th><th class="number">Bestand</th><th class="number">Eintritte</th><th class="number">Abgänge</th></tr></thead>
    <tbody>
    @forelse ($memberTrend as $month)
        <tr>
            <td>{{ $month['label'] }}</td>
            <td class="number">{{ $number($month['active']) }}</td>
            <td class="number">{{ $number($month['joined']) }}</td>
            <td class="number">{{ $number($month['departed']) }}</td>
        </tr>
    @empty
        <tr><td colspan="4">Keine Daten im gewählten Zeitraum.</td></tr>
    @endforelse
    </tbody>
</table>

<section class="page">
    <h2>Mitglieder</h2>
    @foreach ([
        'membership_types' => 'Mitgliedsarten',
        'age_groups' => 'Altersgruppen',
        'genders' => 'Geschlecht',
        'payment_methods' => 'Zahlungsarten',
        'cities' => 'Wohnorte',
    ] as $key => $title)
        <div class="keep">
            <h3>{{ $title }}</h3>
            <table class="compact breakdown">
                <thead><tr><th>Ausprägung</th><th class="number">Mitglieder</th></tr></thead>
                <tbody>
                @forelse ($memberBreakdowns[$key] as $item)
                    <tr><td>{{ $item['label'] }}</td><td class="number">{{ $number($item['count']) }}</td></tr>
                @empty
                    <tr><td colspan="2">Keine Daten vorhanden.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endforeach
    @foreach ($departments as $field)
        <div class="keep">
            <h3>{{ $field['label'] }}</h3>
            <table class="compact breakdown">
                <thead><tr><th>Abteilung</th><th class="number">Mitglieder</th></tr></thead>
                <tbody>
                @forelse ($field['items'] as $item)
                    <tr><td>{{ $item['label'] }}</td><td class="number">{{ $number($item['count']) }}</td></tr>
                @empty
                    <tr><td colspan="2">Keine Daten vorhanden.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endforeach

    <h3>Bestandsstruktur nach Geburtsjahr{{ $departmentLabel !== '' ? ' · '.$departmentLabel : '' }}</h3>
    <table class="compact">
        <thead>
        <tr><th>Geburtsjahr</th><th class="number">Weiblich</th><th class="number">Männlich</th><th class="number">Divers</th><th class="number">Ohne Angabe</th><th class="number">Gesamt</th></tr>
        </thead>
        <tbody>
        @forelse ($stockReport as $row)
            <tr>
                <td>{{ $row['label'] }}</td>
                <td class="number">{{ $number($row['female']) }}</td>
                <td class="number">{{ $number($row['male']) }}</td>
                <td class="number">{{ $number($row['diverse']) }}</td>
                <td class="number">{{ $number($row['unspecified']) }}</td>
                <td class="number">{{ $number($row['total']) }}</td>
            </tr>
        @empty
            <tr><td colspan="6">Keine aktiven Mitglieder vorhanden.</td></tr>
        @endforelse
        </tbody>
        @if ($stockReport)
            <tfoot><tr><th>Gesamt</th><th class="number">{{ $number($stockTotals['female']) }}</th><th class="number">{{ $number($stockTotals['male']) }}</th><th class="number">{{ $number($stockTotals['diverse']) }}</th><th class="number">{{ $number($stockTotals['unspecified']) }}</th><th class="number">{{ $number($stockTotals['total']) }}</th></tr></tfoot>
        @endif
    </table>
</section>

<section class="page">
    <h2>Finanzen</h2>
    <table class="summary keep">
        <tr>
            <td><span class="muted">Sollstellungen</span><strong>{{ $money($finances['contributions']['assessed_cents']) }}</strong></td>
            <td><span class="muted">Bezahlt</span><strong>{{ $money($finances['contributions']['paid_cents']) }}</strong></td>
            <td><span class="muted">Offen</span><strong>{{ $money($finances['contributions']['open_cents']) }}</strong></td>
            <td><span class="muted">Überfällig</span><strong>{{ $money($finances['contributions']['overdue_cents']) }}</strong></td>
            <td><span class="muted">Zahlungsquote</span><strong>{{ $finances['contributions']['collection_rate'] }} %</strong></td>
        </tr>
    </table>

    <h3>Sollstellungen und Spenden im Zeitverlauf</h3>
    <table class="compact">
        <thead><tr><th>Monat</th><th class="number">Sollstellungen</th><th class="number">Spenden</th></tr></thead>
        <tbody>
        @forelse ($finances['monthly'] as $month)
            <tr><td>{{ $month['label'] }}</td><td class="number">{{ $money($month['contributions_cents']) }}</td><td class="number">{{ $money($month['donations_cents']) }}</td></tr>
        @empty
            <tr><td colspan="3">Keine Finanzdaten im gewählten Zeitraum.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="keep">
        <h3>Spenden</h3>
        <p>{{ $number($finances['donations']['count']) }} Spenden · Gesamt {{ $money($finances['donations']['amount_cents']) }} · Durchschnitt {{ $money($finances['donations']['average_cents']) }}</p>
        <table class="compact">
            <thead><tr><th>Spendenart</th><th class="number">Anzahl</th><th class="number">Betrag</th></tr></thead>
            <tbody>
            @forelse ($finances['donations']['by_type'] as $item)
                <tr><td>{{ $item['label'] }}</td><td class="number">{{ $number($item['count']) }}</td><td class="number">{{ $money($item['amount_cents']) }}</td></tr>
            @empty
                <tr><td colspan="3">Keine Spenden im gewählten Zeitraum.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="page">
    <h2>Datenqualität</h2>
    <p>Vollständigkeit des aktiven Mitgliederbestands zum {{ $asOf->format('d.m.Y') }}: <strong>{{ $dataQuality['score'] }} %</strong></p>
    <table>
        <thead><tr><th>Prüfung</th><th>Hinweis</th><th class="number">Betroffen</th><th class="number">Anteil</th></tr></thead>
        <tbody>
        @foreach ($dataQuality['checks'] as $check)
            <tr>
                <td>{{ $check['label'] }}</td>
                <td>{{ $check['description'] }}</td>
                <td class="number">{{ $number($check['count']) }}</td>
                <td class="number">{{ $check['percentage'] }} %</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</section>

<div class="footer">
    Erstellt am {{ $createdAt->format('d.m.Y H:i') }} Uhr · {{ $club['name'] ?? config('app.name') }}
</div>
</body>
</html>
