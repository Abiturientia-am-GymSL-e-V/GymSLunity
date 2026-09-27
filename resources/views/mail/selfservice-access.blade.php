<x-mail.layout
    :club-name="$clubName"
    :logo-url="$logoUrl"
    :title="$actionLabel"
    :preheader="$messageText"
>
<div style="white-space:pre-line">{{ $messageText }}</div>
<p style="margin:28px 0"><a href="{{ $url }}" style="display:inline-block;padding:13px 20px;border-radius:8px;background:#17212b;color:#ffffff;text-decoration:none;font-weight:700">{{ $actionLabel }}</a></p>
@if($directConfirmation)
<p style="color:#53606d;font-size:14px">Der Link ist 15 Minuten gültig und kann einmal verwendet werden. Beim Öffnen wird {{ \App\Support\FormOfAddress::choose('deine', 'Ihre') }} neue E-Mail-Adresse direkt bestätigt.</p>
@else
<p style="color:#53606d;font-size:14px">Der Link ist 15 Minuten gültig und kann einmal verwendet werden. Erst die Bestätigung auf der geöffneten Seite löst ihn ein.</p>
<p style="color:#53606d;font-size:14px">Falls die Schaltfläche nicht funktioniert, {{ \App\Support\FormOfAddress::choose('öffne', 'öffnen Sie') }} {{ $confirmationPageLabel }} und {{ \App\Support\FormOfAddress::choose('füge', 'fügen Sie') }} diesen Bestätigungscode ein:</p>
<p style="padding:12px;border-radius:8px;background:#f4f6f8;font-family:monospace;font-size:13px;word-break:break-all">{{ $token }}</p>
@endif
<p style="margin-bottom:0;color:#53606d;font-size:13px">{{ \App\Support\FormOfAddress::choose('Falls du dies nicht angefordert hast, kannst du diese Nachricht ignorieren.', 'Falls Sie dies nicht angefordert haben, können Sie diese Nachricht ignorieren.') }}</p>
</x-mail.layout>
