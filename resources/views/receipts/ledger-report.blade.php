<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Quittungsbuch</title>
    @include('payments.report-styles')
</head>
<body>
    <div class="report-header">
        <div class="report-heading"><h1>{{ $club['name'] ?? config('app.name') }} · Quittungsbuch</h1></div>
        @if ($logo)<div class="report-brand"><img class="report-logo" src="{{ $logo }}" alt=""></div>@endif
    </div>
    <p>
        Stand {{ $printedAt->format('d.m.Y H:i T') }} · {{ $receipts->count() }} Quittungen ·
        Gesamt {{ number_format($receipts->sum('amount_cents') / 100, 2, ',', '.') }} €
        @if(!empty($filters['from'])) · ab {{ \Carbon\CarbonImmutable::parse($filters['from'])->format('d.m.Y') }}@endif
        @if(!empty($filters['to'])) · bis {{ \Carbon\CarbonImmutable::parse($filters['to'])->format('d.m.Y') }}@endif
    </p>
    <table>
        <thead><tr><th>Quittung</th><th>Datum</th><th>Zahlender</th><th>Empfänger</th><th>Zahlungsgrund</th><th>Status</th><th class="number">Betrag</th></tr></thead>
        <tbody>
        @forelse ($receipts as $receipt)
            @php($status = $receipt->cancelled_at ? 'Storniert' : ($receipt->exported_at ? 'Ausgegeben' : 'Nicht ausgegeben'))
            <tr>
                <td>{{ $receipt->receipt_number }}</td>
                <td>{{ $receipt->receipt_date->format('d.m.Y') }}</td>
                <td>{!! nl2br(e($receipt->payer)) !!}</td>
                <td>{!! nl2br(e($receipt->payee)) !!}</td>
                <td>{{ $receipt->purpose }}</td>
                <td>{{ $status }}</td>
                <td class="number">{{ number_format($receipt->amount_cents / 100, 2, ',', '.') }} {{ $receipt->currency }}</td>
            </tr>
        @empty
            <tr><td colspan="7">Keine Quittungen entsprechen den gewählten Filtern.</td></tr>
        @endforelse
        </tbody>
    </table>
</body>
</html>
