<x-mail.layout
    :club-name="$clubName"
    :logo-url="$logoUrl"
    title="E-Mail-Konfiguration erfolgreich"
    preheader="Der Testversand aus GymSLunity war erfolgreich."
>
<p style="margin-top:0">Hallo,</p>
<p>der Testversand aus GymSLunity war erfolgreich.</p>
<p><strong>Verwendeter Transport:</strong> {{ $driverLabel }}<br>
<strong>Empfänger:</strong> {{ $recipient }}<br>
<strong>Zeitpunkt:</strong> {{ now()->setTimezone(config('app.display_timezone'))->format('d.m.Y H:i T') }}</p>
<p style="margin-bottom:0">Diese Nachricht bestätigt, dass die eingegebenen E-Mail-Einstellungen eine Nachricht an den gewählten Transport übergeben konnten.</p>
</x-mail.layout>
