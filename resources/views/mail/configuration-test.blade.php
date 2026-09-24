<p>Hallo,</p>
<p>der Testversand aus GymSLunity war erfolgreich.</p>
<p><strong>Verwendeter Transport:</strong> {{ $driverLabel }}<br>
<strong>Empfänger:</strong> {{ $recipient }}<br>
<strong>Zeitpunkt:</strong> {{ now()->setTimezone(config('app.display_timezone'))->format('d.m.Y H:i T') }}</p>
<p>Diese Nachricht bestätigt, dass die eingegebenen E-Mail-Einstellungen eine Nachricht an den gewählten Transport übergeben konnten.</p>
