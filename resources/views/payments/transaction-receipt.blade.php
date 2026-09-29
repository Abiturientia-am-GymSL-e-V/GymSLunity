<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} KB-{{ $transaction->id }}</title>
    @include('payments.invoice-styles')
</head>
<body>
<section class="invoice">
    <div class="top">
        <div>@if ($logo)<img class="logo" src="{{ $logo }}" alt="Vereinslogo">@endif</div>
        <div class="club">
            <strong>{{ $club['name'] ?? config('app.name') }}</strong><br>
            {{ $club['street'] ?? '' }}<br>
            {{ trim(($club['postal_code'] ?? '').' '.($club['city'] ?? '')) }}
        </div>
    </div>
    <div class="address">
        {{ $member->first_name }} {{ $member->last_name }}<br>
        {{ $member->street }}<br>
        {{ trim(($member->postal_code ?? '').' '.($member->city ?? '')) }}
    </div>
    <h1>{{ $title }}</h1>
    <div class="meta">
        Beleg-Nr.: KB-{{ $transaction->id }} ·
        Buchungsdatum: {{ $transaction->booking_date->format('d.m.Y') }} ·
        Mitgliedsnummer: {{ $member->member_number }}
    </div>
    @if ($transaction->amount_cents < 0)
        <p>Wir bestätigen den Eingang von {{ number_format(abs($transaction->amount_cents) / 100, 2, ',', '.') }} € auf dem Beitragskonto.</p>
    @else
        <p>Auf dem Beitragskonto wurde folgender Betrag belastet:</p>
    @endif
    <table class="items">
        <thead><tr><th>Beschreibung</th><th>Art</th><th>Referenz</th><th class="number">Betrag</th></tr></thead>
        <tbody>
            <tr>
                <td>{{ $transaction->description }}</td>
                <td>{{ $kind }}</td>
                <td>{{ $transaction->reference ?: '–' }}</td>
                <td class="number">{{ number_format(abs($transaction->amount_cents) / 100, 2, ',', '.') }} €</td>
            </tr>
        </tbody>
    </table>
    <div class="note">
        Dieser Beleg wurde maschinell aus der Kontobuchung erstellt und ist ohne Unterschrift gültig.
        Er ist keine Zuwendungsbestätigung.
    </div>
    <footer>
        {{ $club['name'] ?? config('app.name') }}
        @if (! empty($club['iban'])) · IBAN {{ \App\Support\Iban::format((string) $club['iban']) }}@endif
        @if (! empty($club['email'])) · {{ $club['email'] }}@endif
    </footer>
</section>
</body>
</html>
