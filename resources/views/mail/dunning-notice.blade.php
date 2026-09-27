<x-mail.layout
    :club-name="$clubName"
    :logo-url="$logoUrl"
    title="Zahlungserinnerung"
    :preheader="'Offener Betrag: '.number_format($openCents / 100, 2, ',', '.').' €'"
>
<p style="margin-top:0">Guten Tag {{ $member->first_name }} {{ $member->last_name }},</p>
<p>{{ $formalAddress ? 'Auf Ihrem' : 'Auf deinem' }} Beitragskonto ist derzeit ein Betrag von <strong>{{ number_format($openCents / 100, 2, ',', '.') }} €</strong> offen. {{ $formalAddress ? 'Die Einzelheiten und Zahlungsinformationen finden Sie' : 'Die Einzelheiten und Zahlungsinformationen findest du' }} in der beigefügten Zahlungserinnerung.</p>
@if ($giroCode)
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:24px 0;background:#f4f6f8;border-radius:10px">
<tr>
<td style="padding:20px;vertical-align:top;font-size:14px;line-height:1.6">
<strong>Per Überweisung zahlen</strong><br>
Empfänger: {{ $giroCode['recipient'] }}<br>
IBAN: {{ $giroCode['iban'] }}@if ($giroCode['bic'])<br>BIC: {{ $giroCode['bic'] }}@endif<br>
Betrag: {{ number_format($openCents / 100, 2, ',', '.') }} €<br>
Verwendungszweck: <strong>{{ $giroCode['purpose'] }}</strong>
</td>
<td style="width:140px;padding:20px;vertical-align:top;text-align:right"><img src="{{ $giroCode['image'] }}" alt="Girocode" width="120" height="120" style="display:inline-block;width:120px;height:120px"></td>
</tr>
</table>
@endif
<p>{{ $formalAddress ? 'Falls Sie den Betrag bereits beglichen haben, betrachten Sie diese Nachricht bitte als gegenstandslos.' : 'Falls du den Betrag bereits beglichen hast, betrachte diese Nachricht bitte als gegenstandslos.' }}</p>
<p style="margin-bottom:0">Freundliche Grüße<br>{{ $clubName }}</p>
</x-mail.layout>
