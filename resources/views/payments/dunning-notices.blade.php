<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Zahlungserinnerungen</title>
    @include('payments.invoice-styles')
</head>
<body>
@php
    $formalAddress = \App\Support\FormOfAddress::isFormal($club);
@endphp
@if ($print)
    <div class="actions"><button type="button" onclick="window.print()">Drucken</button></div>
@endif
@foreach ($members as $member)
    @php
        $items = $member->contributionAccount->contributions;
        $openCents = $items->sum(fn ($item) => $item->remainingCents());
        $giroCode = $giroCodes[$member->member_number] ?? null;
    @endphp
    <section @class(['invoice', 'page-break' => ! $loop->last])>
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
        <h1>Zahlungserinnerung</h1>
        <div class="meta">Datum: {{ $createdAt->format('d.m.Y') }} · Mitgliedsnummer: {{ $member->member_number }}</div>
        <p>Guten Tag {{ $member->first_name }} {{ $member->last_name }},</p>
        <p>{{ $formalAddress ? 'Auf Ihrem Beitragskonto' : 'Auf deinem Beitragskonto' }} sind die folgenden Beträge offen:</p>
        <table class="items">
            <thead><tr><th>Beschreibung</th><th>Fällig am</th><th class="number">Offener Betrag</th></tr></thead>
            <tbody>
            @foreach ($items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td>{{ $item->due_date->format('d.m.Y') }}</td>
                    <td class="number">{{ number_format($item->remainingCents() / 100, 2, ',', '.') }} €</td>
                </tr>
            @endforeach
                <tr class="total"><td colspan="2">Gesamt offen</td><td class="number">{{ number_format($openCents / 100, 2, ',', '.') }} €</td></tr>
            </tbody>
        </table>
        <p>{{ $formalAddress
            ? 'Bitte begleichen Sie den offenen Betrag zeitnah. Falls Sie bereits gezahlt haben, betrachten Sie dieses Schreiben bitte als gegenstandslos.'
            : 'Bitte begleiche den offenen Betrag zeitnah. Falls du bereits gezahlt hast, betrachte dieses Schreiben bitte als gegenstandslos.' }}</p>
        @if ($giroCode)
            <div class="payment">
                <div class="payment-details">
                    <strong>Per Überweisung zahlen</strong><br>
                    Empfänger: {{ $giroCode['recipient'] }}<br>
                    IBAN: {{ $giroCode['iban'] }}@if ($giroCode['bic']) · BIC: {{ $giroCode['bic'] }}@endif<br>
                    Betrag: {{ number_format($openCents / 100, 2, ',', '.') }} €<br>
                    Verwendungszweck: <span class="payment-reference">{{ $giroCode['purpose'] }}</span>
                </div>
                <div class="payment-code"><img src="{{ $giroCode['image'] }}" alt="Girocode"></div>
            </div>
        @endif
        <footer>
            {{ $club['name'] ?? config('app.name') }}
            @if (! empty($club['email'])) · {{ $club['email'] }}@endif
        </footer>
    </section>
@endforeach
</body>
</html>
