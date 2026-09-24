<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ $contribution->invoice_number }}</title>
    <style>
        @page { margin: 20mm; } body { color:#172033; font-family:"DejaVu Sans",sans-serif; font-size:10pt; line-height:1.5; }
        .top { display:table; width:100%; margin-bottom:35mm; } .top > div { display:table-cell; vertical-align:top; }
        .logo { max-width:48mm; max-height:22mm; } .club { text-align:right; font-size:9pt; color:#526079; }
        .address { margin-bottom:18mm; } h1 { font-size:20pt; margin:0 0 4mm; } .meta { color:#526079; margin-bottom:10mm; }
        table { width:100%; border-collapse:collapse; } th { text-align:left; background:#eef2f7; } th,td { padding:3mm; border-bottom:1px solid #d8dee9; }
        .number { text-align:right; white-space:nowrap; } .total td { font-weight:bold; border-top:2px solid #526079; }
        .note { margin-top:12mm; padding:4mm; background:#f5f7fa; } footer { position:fixed; bottom:0; width:100%; border-top:1px solid #d8dee9; padding-top:3mm; color:#657187; font-size:8pt; }
        @media print { .actions { display:none; } } .actions { margin-bottom:8mm; padding:3mm; background:#eef2f7; }
    </style>
</head>
<body>
@if($print)<div class="actions"><button onclick="window.print()">Drucken</button></div>@endif
<div class="top"><div>@if($logo)<img class="logo" src="{{ $logo }}" alt="">@endif</div><div class="club"><strong>{{ $club['name'] ?? config('app.name') }}</strong><br>{{ $club['street'] ?? '' }}<br>{{ trim(($club['postal_code'] ?? '').' '.($club['city'] ?? '')) }}</div></div>
<div class="address">{{ $member->first_name }} {{ $member->last_name }}<br>{{ $member->street }}<br>{{ trim(($member->postal_code ?? '').' '.($member->city ?? '')) }}</div>
<h1>Beitragsrechnung</h1>
<div class="meta">Rechnungsnummer: {{ $contribution->invoice_number }} · Rechnungsdatum: {{ $contribution->invoice_created_at?->format('d.m.Y') }} · Mitgliedsnummer: {{ $member->member_number }}</div>
<p>Guten Tag {{ $member->first_name }} {{ $member->last_name }},</p><p>für den folgenden Zeitraum berechnen wir den Mitgliedsbeitrag:</p>
<table><thead><tr><th>Beschreibung</th><th>Zeitraum</th><th class="number">Betrag</th></tr></thead><tbody><tr><td>{{ $contribution->description }}</td><td>{{ $contribution->period_start->format('d.m.Y') }}–{{ $contribution->period_end->format('d.m.Y') }}</td><td class="number">{{ number_format($contribution->amount_cents / 100, 2, ',', '.') }} €</td></tr><tr class="total"><td colspan="2">Gesamtbetrag</td><td class="number">{{ number_format($contribution->amount_cents / 100, 2, ',', '.') }} €</td></tr></tbody></table>
<p>Fällig am {{ $contribution->due_date->format('d.m.Y') }}. Zahlungsart: {{ $contribution->payment_method ?: 'nicht hinterlegt' }}.</p>
@if($contribution->tax_deductible)<div class="note">Hinweis: Dieser Beitrag kann nach Maßgabe der steuerrechtlichen Voraussetzungen als Spende abzugsfähig sein. Diese Rechnung ersetzt keine Zuwendungsbestätigung.</div>@endif
<footer>{{ $club['name'] ?? config('app.name') }}@if(!empty($club['iban'])) · IBAN {{ $club['iban'] }}@endif @if(!empty($club['email'])) · {{ $club['email'] }}@endif</footer>
</body></html>
