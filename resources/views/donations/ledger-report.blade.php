<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Spendenbuch</title>
    @include('payments.report-styles')
</head>
<body>
    <div class="report-header">
        <div class="report-heading"><h1>{{ $club['name'] ?? config('app.name') }} · Spendenbuch</h1></div>
        @if ($logo)<div class="report-brand"><img class="report-logo" src="{{ $logo }}" alt=""></div>@endif
    </div>
    <p>
        Stand {{ $printedAt->format('d.m.Y H:i T') }} · {{ $donations->count() }} Spenden ·
        Gesamt {{ number_format($donations->sum('amount_cents') / 100, 2, ',', '.') }} €
        @if(!empty($filters['from'])) · ab {{ \Carbon\CarbonImmutable::parse($filters['from'])->format('d.m.Y') }}@endif
        @if(!empty($filters['to'])) · bis {{ \Carbon\CarbonImmutable::parse($filters['to'])->format('d.m.Y') }}@endif
    </p>
    <table>
        <thead><tr><th>Nr.</th><th>Datum</th><th>Spender</th><th>Typ</th><th>Zweck</th><th>Status</th><th class="number">Betrag/Wert</th></tr></thead>
        <tbody>
        @forelse ($donations as $donation)
            @php($type = match($donation->donation_type) { 'material' => 'Sachzuwendung', 'membership_fee' => 'Mitgliedsbeitrag', 'expense_waiver' => 'Aufwandsspende', default => 'Geldzuwendung' })
            @php($status = $donation->certificate?->revocation ? 'Widerrufen' : ($donation->certificate ? 'Ausgestellt' : 'Offen'))
            <tr>
                <td>{{ $donation->receipt_number }}</td>
                <td>{{ $donation->donated_at->format('d.m.Y') }}</td>
                <td>{{ $donation->donor_name }}@if($donation->donor_email)<br>{{ $donation->donor_email }}@endif</td>
                <td>{{ $type }}</td>
                <td>{{ $donation->purpose_label }}</td>
                <td>{{ $status }}@if($donation->certificate)<br>{{ $donation->certificate->certificate_number }}@endif</td>
                <td class="number">{{ number_format($donation->amount_cents / 100, 2, ',', '.') }} €</td>
            </tr>
        @empty
            <tr><td colspan="7">Keine Spenden entsprechen den gewählten Filtern.</td></tr>
        @endforelse
        </tbody>
    </table>
</body>
</html>
