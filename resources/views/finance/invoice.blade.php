<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice['invoice_number'] }}</title>
    <style>
        @page { margin: 24mm 18mm 22mm; }
        * { box-sizing: border-box; }
        body { color: #171717; font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; line-height: 1.45; }
        .muted { color: #666; }
        .small { font-size: 8pt; }
        .right { text-align: right; }
        .header { border-bottom: 2px solid #171717; margin-bottom: 30px; padding-bottom: 14px; }
        .logo { float: right; max-height: 55px; max-width: 180px; }
        .seller { font-size: 8pt; margin-bottom: 6px; text-decoration: underline; }
        .address { min-height: 90px; }
        .meta { border-collapse: collapse; float: right; margin-top: -94px; width: 43%; }
        .meta td { padding: 2px 0 2px 8px; vertical-align: top; }
        h1 { font-size: 20pt; margin: 28px 0 8px; }
        table.items { border-collapse: collapse; margin-top: 22px; width: 100%; }
        .items th { border-bottom: 1px solid #333; font-size: 8pt; padding: 6px 4px; text-align: left; }
        .items td { border-bottom: 1px solid #ddd; padding: 7px 4px; vertical-align: top; }
        .items .number { text-align: right; white-space: nowrap; }
        .totals { border-collapse: collapse; margin: 14px 0 22px auto; width: 45%; }
        .totals td { padding: 3px 4px; }
        .totals .grand td { border-top: 2px solid #171717; font-size: 11pt; font-weight: bold; padding-top: 6px; }
        .payment { border: 1px solid #bbb; margin-top: 20px; padding: 12px; }
        .qr { float: right; height: 110px; margin: -3px 0 4px 18px; width: 110px; }
        .footer { border-top: 1px solid #bbb; bottom: -8mm; color: #666; font-size: 7.5pt; left: 0; padding-top: 6px; position: fixed; right: 0; }
        .clear { clear: both; }
    </style>
</head>
<body>
    @php($isCancellation = ($invoice['document_type'] ?? 'invoice') === 'cancellation')
    @php($isPartialCancellation = $isCancellation && ($invoice['cancellation_scope'] ?? 'full') === 'partial')
    <header class="header">
        @if ($logo)<img class="logo" src="{{ $logo }}" alt="">@endif
        <strong>{{ $invoice['seller']['name'] }}</strong><br>
        <span class="muted">{{ $invoice['seller']['street'] }} · {{ $invoice['seller']['postal_code'] }} {{ $invoice['seller']['city'] }}</span>
        <div class="clear"></div>
    </header>

    <div class="seller">{{ $invoice['seller']['name'] }} · {{ $invoice['seller']['street'] }} · {{ $invoice['seller']['postal_code'] }} {{ $invoice['seller']['city'] }}</div>
    <div class="address">
        <strong>{{ $invoice['buyer']['name'] }}</strong><br>
        {{ $invoice['buyer']['street'] }}<br>
        {{ $invoice['buyer']['postal_code'] }} {{ $invoice['buyer']['city'] }}<br>
        {{ $invoice['buyer']['country'] }}
    </div>
    <table class="meta">
        <tr><td class="muted">{{ $isCancellation ? 'Stornonummer' : 'Rechnungsnummer' }}</td><td class="right"><strong>{{ $invoice['invoice_number'] }}</strong></td></tr>
        <tr><td class="muted">{{ $isCancellation ? 'Stornodatum' : 'Rechnungsdatum' }}</td><td class="right">{{ \Carbon\CarbonImmutable::parse($invoice['issue_date'])->format('d.m.Y') }}</td></tr>
        @if ($isCancellation)
            <tr><td class="muted">Stornierte Rechnung</td><td class="right">{{ $invoice['original_invoice']['invoice_number'] }}</td></tr>
            <tr><td class="muted">Ursprüngliches Datum</td><td class="right">{{ \Carbon\CarbonImmutable::parse($invoice['original_invoice']['issue_date'])->format('d.m.Y') }}</td></tr>
        @endif
        <tr><td class="muted">Leistungsdatum</td><td class="right">{{ \Carbon\CarbonImmutable::parse($invoice['service_date'])->format('d.m.Y') }}</td></tr>
        <tr><td class="muted">Käuferreferenz</td><td class="right">{{ $invoice['buyer_reference'] }}</td></tr>
        @unless ($isCancellation)<tr><td class="muted">{{ $invoice['payment_method'] === 'sepa_direct_debit' ? 'Einzug ab' : 'Fällig am' }}</td><td class="right">{{ \Carbon\CarbonImmutable::parse($invoice['due_date'])->format('d.m.Y') }}</td></tr>@endunless
    </table>
    <div class="clear"></div>

    <h1>{{ $isCancellation ? 'Stornorechnung' : 'Rechnung' }}</h1>
    <p>{{ $invoice['buyer']['name'] }},</p>
    @if ($isCancellation)
        @if ($isPartialCancellation)
            <p>wir stornieren die nachfolgend aufgeführten Positionen aus der Rechnung <strong>{{ $invoice['original_invoice']['invoice_number'] }}</strong> vom {{ \Carbon\CarbonImmutable::parse($invoice['original_invoice']['issue_date'])->format('d.m.Y') }}.</p>
        @else
            <p>wir stornieren die Rechnung <strong>{{ $invoice['original_invoice']['invoice_number'] }}</strong> vom {{ \Carbon\CarbonImmutable::parse($invoice['original_invoice']['issue_date'])->format('d.m.Y') }} vollständig.</p>
        @endif
        <p><strong>Stornierungsgrund:</strong> {{ $invoice['cancellation_reason'] }}</p>
    @else
        <p>wir berechnen die folgenden Leistungen:</p>
    @endif

    <table class="items">
        <thead><tr><th>Pos.</th><th>Beschreibung</th><th class="number">Menge</th><th class="number">Einzel netto</th><th class="number">USt.</th><th class="number">Gesamt netto</th></tr></thead>
        <tbody>
        @foreach ($invoice['items'] as $index => $item)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $item['description'] }}@if($item['vat_category'] === 'E')<br><span class="small muted">{{ $item['tax_exemption_reason'] }}</span>@endif</td>
                <td class="number">{{ str_replace('.', ',', $item['quantity']) }} {{ ['C62' => 'Stk.', 'HUR' => 'Std.', 'DAY' => 'Tag(e)'][$item['unit_code']] }}</td>
                <td class="number">{{ $isCancellation ? '−' : '' }}{{ str_replace('.', ',', $item['unit_price_net'] ?? number_format($item['unit_price_cents'] / 100, 2, '.', '')) }} €</td>
                <td class="number">{{ $item['vat_rate'] }} %</td>
                <td class="number">{{ $isCancellation ? '−' : '' }}{{ number_format($item['net_cents'] / 100, 2, ',', '.') }} €</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Nettobetrag</td><td class="right">{{ $isCancellation ? '−' : '' }}{{ number_format($invoice['subtotal_cents'] / 100, 2, ',', '.') }} €</td></tr>
        @foreach (collect($invoice['items'])->groupBy('vat_rate') as $rate => $items)
            <tr><td>Umsatzsteuer {{ $rate }} %</td><td class="right">{{ $isCancellation && $items->sum('tax_cents') !== 0 ? '−' : '' }}{{ number_format($items->sum('tax_cents') / 100, 2, ',', '.') }} €</td></tr>
        @endforeach
        <tr class="grand"><td>{{ $isCancellation ? 'Stornobetrag' : 'Rechnungsbetrag' }}</td><td class="right">{{ $isCancellation ? '−' : '' }}{{ number_format($invoice['total_cents'] / 100, 2, ',', '.') }} €</td></tr>
    </table>

    @if ($invoice['small_business_regulation'] ?? false)
        <p><strong>{{ $invoice['small_business_notice'] ?? 'Steuerbefreiung für Kleinunternehmer gemäß § 19 UStG.' }}</strong></p>
    @endif

    @if ($invoice['notes'] !== '')<p>{!! nl2br(e($invoice['notes'])) !!}</p>@endif

    @unless ($isCancellation)<div class="payment">
        @if ($giroCode)
            <img class="qr" src="{{ $giroCode['image'] }}" alt="GiroCode">
            <strong>Überweisung</strong><br>
            Bitte überweisen Sie {{ number_format($invoice['total_cents'] / 100, 2, ',', '.') }} € bis zum {{ \Carbon\CarbonImmutable::parse($invoice['due_date'])->format('d.m.Y') }}.<br>
            Empfänger: {{ $giroCode['recipient'] }}<br>
            IBAN: {{ $giroCode['iban'] }}@if($giroCode['bic']) · BIC: {{ $giroCode['bic'] }}@endif<br>
            Verwendungszweck: {{ $giroCode['purpose'] }}<br>
            <span class="small muted">Der GiroCode enthält die vorausgefüllten Überweisungsdaten.</span>
        @elseif ($invoice['payment_method'] === 'sepa_direct_debit')
            <strong>SEPA-Lastschrift</strong><br>
            Der Rechnungsbetrag wird ab dem {{ \Carbon\CarbonImmutable::parse($invoice['due_date'])->format('d.m.Y') }} per SEPA-Lastschrift eingezogen.<br>
            Gläubiger-ID: {{ $invoice['seller']['creditor_id'] }} · Mandatsreferenz: {{ $invoice['payment']['mandate_reference'] }}
        @elseif ($invoice['payment_method'] === 'cash')
            <strong>Barzahlung</strong><br>Bitte begleichen Sie den Rechnungsbetrag bis zum Fälligkeitstag in bar.
        @elseif ($invoice['payment_method'] === 'card')
            <strong>Kartenzahlung</strong><br>Bitte begleichen Sie den Rechnungsbetrag bis zum Fälligkeitstag per Karte.
        @else
            <strong>Sonstige Zahlungsart</strong><br>Bitte begleichen Sie den Rechnungsbetrag bis zum Fälligkeitstag.
        @endif
        <div class="clear"></div>
    </div>@endunless

    <footer class="footer">
        {{ $invoice['seller']['name'] }} · {{ $invoice['seller']['street'] }} · {{ $invoice['seller']['postal_code'] }} {{ $invoice['seller']['city'] }} · {{ $invoice['seller']['email'] }} · {{ $invoice['seller']['phone'] }}<br>
        @if($invoice['seller']['tax_number'])Steuernummer: {{ $invoice['seller']['tax_number'] }}@endif
        @if($invoice['seller']['vat_id']) · USt-IdNr.: {{ $invoice['seller']['vat_id'] }}@endif
        @if($invoice['seller']['iban']) · IBAN: {{ \App\Support\Iban::format($invoice['seller']['iban']) }}@endif
        @if($invoice['seller']['bank_name']) · {{ $invoice['seller']['bank_name'] }}@endif
    </footer>
</body>
</html>
