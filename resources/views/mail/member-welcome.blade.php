<x-mail.layout
    :club-name="$clubName"
    :logo-url="$logoUrl"
    :title="$subjectLine"
    :preheader="$preheader"
>
@if($addressChangeRequired)
<x-mail.alert type="error" title="{{ \App\Support\FormOfAddress::choose('Bitte ändere deine E-Mail-Adresse', 'Bitte ändern Sie Ihre E-Mail-Adresse') }}">
{{ \App\Support\FormOfAddress::choose(
    'Diese E-Mail-Adresse ist für den Mitgliederbereich nicht mehr zugelassen. Nach der Anmeldung wirst du aufgefordert, eine andere Adresse zu hinterlegen und zu bestätigen. Bis dahin ist der Mitgliederbereich eingeschränkt.',
    'Diese E-Mail-Adresse ist für den Mitgliederbereich nicht mehr zugelassen. Nach der Anmeldung werden Sie aufgefordert, eine andere Adresse zu hinterlegen und zu bestätigen. Bis dahin ist der Mitgliederbereich eingeschränkt.',
) }}
</x-mail.alert>
@endif
<div style="margin-top:0;white-space:pre-line">{{ $messageText }}</div>
<p style="margin:28px 0"><a href="{{ $url }}" style="display:inline-block;padding:13px 20px;border-radius:8px;background:#17212b;color:#ffffff;text-decoration:none;font-weight:700">Mitgliederbereich öffnen</a></p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 20px;border-radius:8px;background:#f4f6f8">
<tr><td style="padding:14px 16px;font-size:14px;line-height:1.6">
<div><span style="color:#53606d">Anmeldung mit:</span> <strong style="word-break:break-all">{{ $email }}</strong></div>
<div><span style="color:#53606d">Mitgliedsnummer:</span> <strong>{{ $memberNumber }}</strong></div>
</td></tr>
</table>
<p style="color:#53606d;font-size:14px">{{ \App\Support\FormOfAddress::choose('Falls die Schaltfläche nicht funktioniert, öffne diese Adresse im Browser:', 'Falls die Schaltfläche nicht funktioniert, öffnen Sie diese Adresse im Browser:') }}<br><span style="word-break:break-all">{{ $url }}</span></p>
<p style="margin-bottom:0;color:#53606d;font-size:13px">{{ \App\Support\FormOfAddress::choose('Du erhältst diese Nachricht, weil deine E-Mail-Adresse bei '.$clubName.' hinterlegt ist.', 'Sie erhalten diese Nachricht, weil Ihre E-Mail-Adresse bei '.$clubName.' hinterlegt ist.') }}</p>
</x-mail.layout>
