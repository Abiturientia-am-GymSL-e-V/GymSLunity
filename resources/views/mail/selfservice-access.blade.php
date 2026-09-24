<p>Mit diesem Link kannst du {{ $purpose === 'email' ? 'deine neue E-Mail-Adresse bestätigen' : 'den Mitgliederbereich öffnen' }}:</p>
<p><a href="{{ $url }}">{{ $purpose === 'email' ? 'E-Mail-Adresse bestätigen' : 'Zugang bestätigen' }}</a></p>
<p>Der Link ist 15 Minuten gültig und kann einmal verwendet werden. Erst die Bestätigung auf der geöffneten Seite löst ihn ein.</p>
<p>Falls der Link nicht funktioniert, öffne den Mitgliederzugang und füge diesen Zugangscode ein:</p>
<p>{{ $token }}</p>
<p>Falls du dies nicht angefordert hast, kannst du diese Nachricht ignorieren.</p>
