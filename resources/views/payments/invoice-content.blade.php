<section @class(['invoice', 'page-break' => $pageBreak ?? false])>
    @php
        $formalAddress = \App\Support\FormOfAddress::isFormal($club);
    @endphp
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
    <h1>Beitragsrechnung</h1>
    <div class="meta">
        Rechnungsnummer: {{ $contribution->invoice_number }} ·
        Rechnungsdatum: {{ $contribution->invoice_created_at?->format('d.m.Y') }} ·
        Mitgliedsnummer: {{ $member->member_number }}
    </div>
    <p>Guten Tag {{ $member->first_name }} {{ $member->last_name }},</p>
    <p>für den folgenden Zeitraum berechnen wir den Mitgliedsbeitrag:</p>
    <table class="items">
        <thead><tr><th>Beschreibung</th><th>Zeitraum</th><th class="number">Betrag</th></tr></thead>
        <tbody>
            <tr>
                <td>{{ $contribution->description }}</td>
                <td>{{ $contribution->period_start->format('d.m.Y') }}–{{ $contribution->period_end->format('d.m.Y') }}</td>
                <td class="number">{{ number_format($contribution->amount_cents / 100, 2, ',', '.') }} €</td>
            </tr>
            <tr class="total"><td colspan="2">Gesamtbetrag</td><td class="number">{{ number_format($contribution->amount_cents / 100, 2, ',', '.') }} €</td></tr>
        </tbody>
    </table>
    @if ($giroCode)
        <div class="payment">
            <div class="payment-details">
                <strong>{{ $formalAddress ? 'Bitte überweisen Sie' : 'Bitte überweise' }} den fälligen Betrag bis zum {{ $contribution->due_date->format('d.m.Y') }}.</strong><br>
                Empfänger: {{ $giroCode['recipient'] }}<br>
                IBAN: {{ $giroCode['iban'] }}@if ($giroCode['bic']) · BIC: {{ $giroCode['bic'] }}@endif<br>
                Betrag: {{ number_format($contribution->remainingCents() / 100, 2, ',', '.') }} €<br>
                Verwendungszweck: <span class="payment-reference">{{ $giroCode['purpose'] }}</span><br>
                <small>{{ $formalAddress ? 'Bitte verwenden Sie' : 'Bitte verwende' }} diesen Verwendungszweck unverändert, damit die Zahlung automatisch zugeordnet werden kann.</small>
            </div>
            <div class="payment-code"><img src="{{ $giroCode['image'] }}" alt="Girocode"></div>
        </div>
    @elseif ($contribution->payment_method === 'SEPA-Lastschrift')
        <p>Der fällige Betrag wird zum {{ $contribution->due_date->format('d.m.Y') }} per SEPA-Lastschrift eingezogen.</p>
    @else
        <p>Fällig am {{ $contribution->due_date->format('d.m.Y') }}. Zahlungsart: {{ $contribution->payment_method ?: 'nicht hinterlegt' }}.</p>
    @endif
    @if ($contribution->tax_deductible)
        <div class="note">Hinweis: Dieser Beitrag kann nach Maßgabe der steuerrechtlichen Voraussetzungen als Spende abzugsfähig sein. Diese Rechnung ersetzt keine Zuwendungsbestätigung.</div>
    @endif
    <footer>
        {{ $club['name'] ?? config('app.name') }}
        @if (! empty($club['iban'])) · IBAN {{ \App\Support\Iban::format((string) $club['iban']) }}@endif
        @if (! empty($club['email'])) · {{ $club['email'] }}@endif
    </footer>
</section>
