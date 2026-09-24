<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ $snapshot['certificate_number'] }}</title>
    <style>
        @page { size: A4 portrait; margin: 12mm 14mm; }
        * { box-sizing: border-box; }
        body { color: #111827; font-family: "DejaVu Sans", sans-serif; font-size: 8.3pt; line-height: 1.3; margin: 0; }
        .issuer { border: 1px solid #4b5563; min-height: 15mm; padding: 2.5mm; margin-bottom: 4mm; }
        .issuer-label, .field-label { color: #4b5563; font-size: 7pt; }
        h1 { font-size: 14pt; line-height: 1.2; margin: 0 0 1.5mm; }
        .subtitle { font-size: 8.5pt; margin: 0 0 4mm; }
        .field { border: 1px solid #6b7280; min-height: 14mm; padding: 2mm; margin-bottom: 3mm; }
        .amounts { border-collapse: collapse; width: 100%; margin-bottom: 3mm; }
        .amounts td { border: 1px solid #6b7280; padding: 2mm; vertical-align: top; }
        .amounts td:nth-child(1) { width: 24%; } .amounts td:nth-child(2) { width: 51%; } .amounts td:nth-child(3) { width: 25%; }
        .checkline { margin: 2mm 0; }
        .box { border: 1px solid #111827; display: inline-block; font-weight: bold; height: 3.5mm; line-height: 3.2mm; margin: 0 1mm 0 2mm; text-align: center; width: 3.5mm; }
        .tax { border-top: 1px solid #9ca3af; margin-top: 3mm; padding-top: 3mm; }
        .signature { border-top: 1px solid #9ca3af; margin-top: 4mm; padding-top: 3mm; }
        .signature-image { display: block; margin: 1mm 0; max-height: 13mm; max-width: 55mm; }
        .signature-code { font-family: "DejaVu Sans Mono", monospace; font-size: 6.5pt; word-break: break-all; }
        .notice { border-top: 1px solid #111827; font-size: 7pt; line-height: 1.22; margin-top: 4mm; padding-top: 2mm; }
        p { margin: 0 0 2.2mm; }
        strong { font-weight: 700; }
    </style>
</head>
<body>
@php($donation = $snapshot['donation'])
@php($club = $snapshot['club'])
<div class="issuer">
    <div class="issuer-label">Aussteller (Bezeichnung und Anschrift der steuerbegünstigten Einrichtung)</div>
    <strong>{{ $club['name'] }}</strong>, {{ $club['street'] }}, {{ $club['postal_code'] }} {{ $club['city'] }}
    @if($club['register_number'])<br>Vereinsregister: {{ $club['register_number'] }}@if($club['register_court']), {{ $club['register_court'] }}@endif @endif
</div>

@if($donation['donation_type'] === 'material')
    <h1>Bestätigung über Sachzuwendungen</h1>
@else
    <h1>Bestätigung über Geldzuwendungen/Mitgliedsbeitrag</h1>
@endif
<p class="subtitle">im Sinne des § 10b des Einkommensteuergesetzes an eine der in § 5 Abs. 1 Nr. 9 des Körperschaftsteuergesetzes bezeichneten Körperschaften, Personenvereinigungen oder Vermögensmassen</p>

<div class="field">
    <div class="field-label">Name und Anschrift des Zuwendenden</div>
    <strong>{{ $donation['donor_name'] }}</strong><br>{{ $donation['donor_street'] }}<br>{{ $donation['donor_postal_code'] }} {{ $donation['donor_city'] }}@if($donation['donor_country'] !== 'DE'), {{ $donation['donor_country'] }}@endif
</div>

<table class="amounts">
    <tr>
        <td><span class="field-label">{{ $donation['donation_type'] === 'material' ? 'Wert' : 'Betrag' }} der Zuwendung – in Ziffern –</span><br><strong>{{ number_format($donation['amount_cents'] / 100, 2, ',', '.') }} €</strong></td>
        <td><span class="field-label">– in Buchstaben –</span><br>{{ $amountWords }}</td>
        <td><span class="field-label">Tag der Zuwendung</span><br><strong>{{ \Carbon\CarbonImmutable::parse($donation['donated_at'])->format('d.m.Y') }}</strong></td>
    </tr>
</table>

@if($donation['donation_type'] === 'material')
    <div class="field">
        <div class="field-label">Genaue Bezeichnung der Sachzuwendung mit Alter, Zustand, Kaufpreis usw.</div>
        {{ $donation['description'] }}
    </div>
    <div class="checkline"><span class="box">{{ $donation['asset_origin'] === 'business' ? 'X' : '' }}</span> Die Sachzuwendung stammt nach den Angaben des Zuwendenden aus dem Betriebsvermögen. Die Zuwendung wurde nach dem Wert der Entnahme (ggf. mit dem niedrigeren gemeinen Wert) und nach der Umsatzsteuer, die auf die Entnahme entfällt, bewertet.</div>
    <div class="checkline"><span class="box">{{ $donation['asset_origin'] === 'private' ? 'X' : '' }}</span> Die Sachzuwendung stammt nach den Angaben des Zuwendenden aus dem Privatvermögen.</div>
    <div class="checkline"><span class="box">{{ $donation['asset_origin'] === 'unknown' ? 'X' : '' }}</span> Der Zuwendende hat trotz Aufforderung keine Angaben zur Herkunft der Sachzuwendung gemacht.</div>
    <div class="checkline"><span class="box">{{ $donation['valuation_document_reference'] ? 'X' : '' }}</span> Geeignete Unterlagen, die zur Wertermittlung gedient haben, z. B. Rechnung, Gutachten, liegen vor.@if($donation['valuation_document_reference']) ({{ $donation['valuation_document_reference'] }})@endif</div>
@else
    <div class="checkline">Es handelt sich um den Verzicht auf die Erstattung von Aufwendungen Ja <span class="box">{{ $donation['expense_waiver'] ? 'X' : '' }}</span> Nein <span class="box">{{ $donation['expense_waiver'] ? '' : 'X' }}</span></div>
@endif

<div class="tax">
    <p>{{ $taxStatement }}</p>
    <p>Es wird bestätigt, dass die Zuwendung nur zur {{ $donation['purpose_label'] }} verwendet wird.</p>
    @if(!$club['contributions_tax_deductible'] && $donation['donation_type'] !== 'material')
        <p><strong>Nur für steuerbegünstigte Einrichtungen, bei denen die Mitgliedsbeiträge steuerlich nicht abziehbar sind:</strong><br>Es wird bestätigt, dass es sich nicht um einen Mitgliedsbeitrag handelt, dessen Abzug nach § 10b Abs. 1 des Einkommensteuergesetzes ausgeschlossen ist.</p>
    @endif
</div>

<div class="signature">
    <strong>{{ $club['certificate_location'] }}, {{ \Carbon\CarbonImmutable::parse($snapshot['signed_at'])->format('d.m.Y') }}</strong><br>
    @if($signatureImage)
        <img class="signature-image" src="{{ $signatureImage }}" alt="Unterschrift">
        {{ $snapshot['signature_method'] === 'profile' ? 'Mit der Profil-Unterschrift' : 'Eigenhändig auf dem Gerät' }} unterzeichnet durch {{ $snapshot['signed_by'] }} am {{ \Carbon\CarbonImmutable::parse($snapshot['signed_at'])->format('d.m.Y H:i') }} Uhr<br>
    @else
        Digital freigegeben durch {{ $snapshot['signed_by'] }} am {{ \Carbon\CarbonImmutable::parse($snapshot['signed_at'])->format('d.m.Y H:i') }} Uhr<br>
    @endif
    <span class="signature-code">Signaturcode: {{ $snapshot['digital_signature'] }} · Beleg: {{ $snapshot['certificate_number'] }}</span><br>
    <span class="field-label">(Ort, Datum und Signatur des Zuwendungsempfängers)</span>
</div>

<div class="notice">
    <strong>Hinweis:</strong><br>
    Wer vorsätzlich oder grob fahrlässig eine unrichtige Zuwendungsbestätigung erstellt oder veranlasst, dass Zuwendungen nicht zu den in der Zuwendungsbestätigung angegebenen steuerbegünstigten Zwecken verwendet werden, haftet für die entgangene Steuer (§ 10b Abs. 4 EStG, § 9 Abs. 3 KStG, § 9 Nr. 5 GewStG).<br>
    Diese Bestätigung wird nicht als Nachweis für die steuerliche Berücksichtigung der Zuwendung anerkannt, wenn das Datum des Freistellungsbescheides länger als 5 Jahre bzw. das Datum der Feststellung der Einhaltung der satzungsmäßigen Voraussetzungen nach § 60a Abs. 1 AO länger als 3 Jahre seit Ausstellung des Bescheides zurückliegt (§ 63 Abs. 5 AO).
</div>
</body>
</html>
