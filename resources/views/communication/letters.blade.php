<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8"><title>Serienbrief</title>
<style>
@page { size: A4 portrait; margin: 18mm 20mm 20mm; }
body { margin: 0; font-family: 'DejaVu Sans', sans-serif; color: #172b3f; font-size: 10.5pt; line-height: 1.55; }
.letter { page-break-after: always; min-height: 250mm; position: relative; }
.letter:last-child { page-break-after: auto; }
.header { height: 23mm; border-bottom: 1px solid #dce3e9; margin-bottom: 12mm; }
.logo { float: right; max-width: 55mm; max-height: 18mm; }
.sender { color: #526577; font-size: 8pt; padding-top: 2mm; }
.address { white-space: pre-line; min-height: 34mm; margin-bottom: 9mm; }
.meta { text-align: right; color: #526577; font-size: 9pt; margin-bottom: 8mm; }
h1 { font-size: 13pt; margin: 0 0 7mm; }
.body p { margin: 0 0 4mm; }
.body h1, .body h2, .body h3 { margin: 5mm 0 3mm; line-height: 1.25; }
.body h1 { font-size: 16pt; } .body h2 { font-size: 14pt; } .body h3 { font-size: 12pt; }
.body ul, .body ol { margin: 2mm 0 4mm 6mm; padding-left: 5mm; }
.body blockquote { margin: 4mm 0; padding-left: 4mm; border-left: 1mm solid #dce3e9; color: #526577; }
.body img { display: block; max-width: 100%; max-height: 100mm; width: auto; height: auto; margin: 4mm 0; }
.body a { color: #1f5f99; text-decoration: underline; }
.footer { position: absolute; bottom: 0; width: 100%; border-top: 1px solid #dce3e9; padding-top: 3mm; color: #526577; font-size: 8pt; }
</style>
</head>
<body>
@foreach($letters as $letter)
<section class="letter">
    <header class="header">
        @if($logo)<img class="logo" src="{{ $logo }}" alt="Vereinslogo">@endif
        <div class="sender">{{ $club['name'] ?? config('app.name') }}@if(!empty($club['street'])) · {{ $club['street'] }}@endif @if(!empty($club['postal_code']) || !empty($club['city'])) · {{ $club['postal_code'] ?? '' }} {{ $club['city'] ?? '' }}@endif</div>
    </header>
    <div class="address">{{ $letter['address'] }}</div>
    <div class="meta">{{ $club['city'] ?? '' }}@if(!empty($club['city'])), @endif{{ now()->setTimezone(config('app.display_timezone'))->format('d.m.Y') }}</div>
    <h1>{{ $letter['subject'] }}</h1>
    <div class="body">{!! $letter['body'] !!}</div>
    <footer class="footer">
        {{ $club['name'] ?? config('app.name') }}
        @if(!empty($club['email'])) · {{ $club['email'] }}@endif
        @if(!empty($club['phone'])) · {{ $club['phone'] }}@endif
        @if(!empty($club['website'])) · {{ $club['website'] }}@endif
        · Mitglied {{ $letter['member_number'] }}
    </footer>
</section>
@endforeach
</body>
</html>
