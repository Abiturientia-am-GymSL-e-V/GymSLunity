<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Rechnungsbuch</title>
    @include('payments.report-styles')
</head>
<body>
    <div class="report-header">
        <div class="report-heading"><h1>{{ $club['name'] ?? config('app.name') }} · Rechnungsbuch</h1></div>
        @if ($logo)<div class="report-brand"><img class="report-logo" src="{{ $logo }}" alt=""></div>@endif
    </div>
    <p>
        Stand {{ $printedAt->format('d.m.Y H:i T') }} · {{ $invoices->count() }} Belege ·
        Saldo {{ number_format($invoices->sum(fn ($invoice) => $invoice->document_type === 'cancellation' ? -$invoice->total_cents : $invoice->total_cents) / 100, 2, ',', '.') }} €
        @if(!empty($filters['from'])) · ab {{ \Carbon\CarbonImmutable::parse($filters['from'])->format('d.m.Y') }}@endif
        @if(!empty($filters['to'])) · bis {{ \Carbon\CarbonImmutable::parse($filters['to'])->format('d.m.Y') }}@endif
    </p>
    <table>
        <thead><tr><th>Beleg</th><th>Datum / Fällig</th><th>Empfänger</th><th>Belegart</th><th>Zahlungsart</th><th>Status</th><th class="number">Betrag</th></tr></thead>
        <tbody>
        @forelse ($invoices as $invoice)
            @php($status = $invoice->document_type === 'cancellation' ? 'Stornorechnung' : match($invoice->status) { 'paid' => 'Bezahlt', 'cancelled' => 'Storniert', default => 'Offen' })
            @php($payment = match($invoice->payment_method) { 'sepa_direct_debit' => 'SEPA-Lastschrift', 'cash' => 'Bar', 'card' => 'Karte', 'other' => 'Sonstige', default => 'Überweisung' })
            <tr>
                <td>{{ $invoice->invoice_number }}</td>
                <td>{{ $invoice->issue_date->format('d.m.Y') }}<br>fällig {{ $invoice->due_date->format('d.m.Y') }}</td>
                <td>{{ $invoice->recipient_name }}<br>{{ $invoice->recipient_email }}</td>
                <td>{{ $invoice->document_type === 'cancellation' ? 'Stornorechnung' : 'Rechnung' }}</td>
                <td>{{ $invoice->document_type === 'cancellation' ? 'Gegenbeleg' : $payment }}</td>
                <td>{{ $status }}</td>
                <td class="number">{{ $invoice->document_type === 'cancellation' ? '−' : '' }}{{ number_format($invoice->total_cents / 100, 2, ',', '.') }} {{ $invoice->currency }}</td>
            </tr>
        @empty
            <tr><td colspan="7">Keine Rechnungsbelege entsprechen den gewählten Filtern.</td></tr>
        @endforelse
        </tbody>
    </table>
</body>
</html>
