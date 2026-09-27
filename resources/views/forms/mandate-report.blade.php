<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Mandatsbuch</title>
    @include('payments.report-styles')
</head>
<body>
    <div class="report-header">
        <div class="report-heading"><h1>{{ $club['name'] ?? config('app.name') }} · SEPA-Mandatsbuch</h1></div>
        @if ($logo)<div class="report-brand"><img class="report-logo" src="{{ $logo }}" alt=""></div>@endif
    </div>
    <p>
        Stand {{ $printedAt->format('d.m.Y H:i T') }} · {{ $mandates->count() }} Mandate
        @if(!empty($filters['from'])) · angelegt ab {{ \Carbon\CarbonImmutable::parse($filters['from'])->format('d.m.Y') }}@endif
        @if(!empty($filters['to'])) · angelegt bis {{ \Carbon\CarbonImmutable::parse($filters['to'])->format('d.m.Y') }}@endif
    </p>
    <table>
        <thead><tr><th>Referenz</th><th>Angelegt</th><th>Zahlungspflichtige Person</th><th>IBAN</th><th>Mandatsart</th><th>Status</th><th>Statusdatum / Hinweis</th></tr></thead>
        <tbody>
        @forelse ($mandates as $mandate)
            @php($status = match($mandate->status) { 'signed' => 'Unterschrieben', 'revoked' => 'Widerrufen', default => 'Unterschrift offen' })
            <tr>
                <td>{{ $mandate->mandate_reference }}</td>
                <td>{{ $mandate->created_at->format('d.m.Y') }}</td>
                <td>{{ $mandate->debtor_name }}@if($mandate->debtor_email)<br>{{ $mandate->debtor_email }}@endif</td>
                <td>•••• {{ substr($mandate->iban, -4) }}</td>
                <td>{{ $mandate->mandate_type === 'one_off' ? 'Einmalig' : 'Wiederkehrend' }}</td>
                <td>{{ $status }}</td>
                <td>
                    @if($mandate->status === 'signed' && $mandate->signed_at){{ $mandate->signed_at->format('d.m.Y') }}@endif
                    @if($mandate->status === 'revoked' && $mandate->revoked_at){{ $mandate->revoked_at->format('d.m.Y') }}@if($mandate->revocation_reason)<br>{{ $mandate->revocation_reason }}@endif @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7">Keine SEPA-Mandate entsprechen den gewählten Filtern.</td></tr>
        @endforelse
        </tbody>
    </table>
</body>
</html>
