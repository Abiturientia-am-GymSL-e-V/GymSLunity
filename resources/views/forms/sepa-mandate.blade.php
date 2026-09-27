<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<style>
@page { margin: 34px 42px; }
body { font-family: DejaVu Sans, sans-serif; color:#111827; font-size:10.5pt; line-height:1.45; }
.head { display:table; width:100%; border-bottom:2px solid #111827; padding-bottom:14px; margin-bottom:22px; }
.head > div { display:table-cell; vertical-align:middle; }
.logo { max-height:52px; max-width:180px; }
h1 { font-size:20pt; margin:0 0 4px; } h2 { font-size:11pt; margin:22px 0 8px; }
.muted { color:#6b7280; } .grid { width:100%; border-collapse:collapse; }
.grid td { width:50%; vertical-align:top; padding:7px 10px 7px 0; }
.label { display:block; color:#6b7280; font-size:8pt; text-transform:uppercase; letter-spacing:.04em; }
.text { border:1px solid #d1d5db; background:#f9fafb; padding:13px; border-radius:5px; }
.signature { margin-top:34px; display:table; width:100%; }
.signature > div { display:table-cell; width:50%; vertical-align:bottom; padding-right:24px; }
.line { border-top:1px solid #111827; padding-top:5px; min-height:20px; }
.signature-image { max-height:55px; max-width:220px; margin-bottom:-3px; }
.status { margin-top:24px; padding:10px; border:1px solid #d1d5db; }
</style>
</head>
<body>
<div class="head"><div><h1>SEPA-Lastschriftmandat</h1><div class="muted">{{ ($mandate['mandate_type'] ?? '') === 'one_off' ? 'Einmalige Zahlung' : 'Wiederkehrende Zahlungen' }}</div></div>@if($logo)<div style="text-align:right"><img class="logo" src="{{ $logo }}"></div>@endif</div>
<table class="grid"><tr><td><span class="label">Gläubiger</span>{{ $club['name'] ?? '' }}<br>{{ $club['street'] ?? '' }}<br>{{ $club['postal_code'] ?? '' }} {{ $club['city'] ?? '' }} · {{ $club['country'] ?? '' }}</td><td><span class="label">Gläubiger-Identifikationsnummer</span>{{ $club['creditor_id'] ?? '' }}<br><br><span class="label">Mandatsreferenz</span>{{ $mandate['mandate_reference'] }}</td></tr></table>
<h2>Ermächtigung und Weisung</h2><div class="text">{!! nl2br(e($mandate['mandate_text'])) !!}</div>
<h2>Zahlungspflichtige Person</h2>
<table class="grid"><tr><td><span class="label">Name</span>{{ $mandate['debtor_name'] }}</td><td><span class="label">IBAN</span>{{ trim(chunk_split($mandate['iban'], 4, ' ')) }}</td></tr><tr><td><span class="label">Anschrift</span>{{ $mandate['debtor_street'] }}<br>{{ $mandate['debtor_postal_code'] }} {{ $mandate['debtor_city'] }} · {{ $mandate['debtor_country'] }}</td><td><span class="label">E-Mail</span>{{ $mandate['debtor_email'] ?? '—' }}</td></tr></table>
<div class="signature">
    <div>
        @if($signature)<img class="signature-image" src="{{ $signature }}">@endif
        <div class="line">
            Ort, Datum
            @if(!empty($mandate['signed_at'])): {{ \Illuminate\Support\Carbon::parse($mandate['signed_at'])->format('d.m.Y') }}@endif
        </div>
    </div>
    <div>
        <div style="min-height:55px">@if(!empty($mandate['signed_by_name'])){{ $mandate['signed_by_name'] }}@endif</div>
        <div class="line">Unterschrift der zahlungspflichtigen Person</div>
    </div>
</div>
@if(($mandate['status'] ?? 'pending') === 'signed')
<div class="status">
    Status: <strong>{{ ($mandate['status'] ?? 'pending') === 'signed' ? 'Unterzeichnet' : 'Noch nicht unterzeichnet – nicht für Lastschrifteinzüge verwendbar' }}</strong>
    @if(($mandate['signature_method'] ?? null) === 'digital') · Elektronisch unterzeichnet @endif
    @if(($mandate['signature_method'] ?? null) === 'paper') · Papierunterschrift bestätigt @endif
</div>
@endif
</body></html>
