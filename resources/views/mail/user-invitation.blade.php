@php($address = fn (string $informal, string $formal): string => \App\Support\FormOfAddress::choose($informal, $formal))
<x-mail.layout
    :club-name="$clubName"
    :logo-url="$logoUrl"
    :title="$subjectLine"
    :preheader="$address('Lege über den Link in dieser E-Mail dein Passwort fest.', 'Legen Sie über den Link in dieser E-Mail Ihr Passwort fest.')"
>
<p style="margin-top:0">Hallo {{ $name }},</p>
@if($resent)
<p>{{ $address('hier ist ein neuer Link, um das Passwort für dein Benutzerkonto in der Vereinsverwaltung von '.$clubName.' festzulegen. Frühere Links funktionieren nicht mehr.', 'hier ist ein neuer Link, um das Passwort für Ihr Benutzerkonto in der Vereinsverwaltung von '.$clubName.' festzulegen. Frühere Links funktionieren nicht mehr.') }}</p>
@else
<p>{{ $address('für dich wurde ein Benutzerkonto in der Vereinsverwaltung von '.$clubName.' angelegt. Lege zuerst über die Schaltfläche dein eigenes Passwort fest; danach kannst du dich anmelden.', 'für Sie wurde ein Benutzerkonto in der Vereinsverwaltung von '.$clubName.' angelegt. Legen Sie zuerst über die Schaltfläche Ihr eigenes Passwort fest; danach können Sie sich anmelden.') }}</p>
@endif
<p style="margin:28px 0"><a href="{{ $url }}" style="display:inline-block;padding:13px 20px;border-radius:8px;background:#17212b;color:#ffffff;text-decoration:none;font-weight:700">Passwort festlegen</a></p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 20px;border-radius:8px;background:#f4f6f8">
<tr><td style="padding:14px 16px;font-size:14px;line-height:1.6">
<div><span style="color:#53606d">Anmeldung mit:</span> <strong style="word-break:break-all">{{ $email }}</strong></div>
<div><span style="color:#53606d">Rollen:</span> <strong>{{ $roles === [] ? 'noch keine' : implode(', ', $roles) }}</strong></div>
<div><span style="color:#53606d">Anmeldeseite:</span> <span style="word-break:break-all">{{ $loginUrl }}</span></div>
</td></tr>
</table>
@if($requiresTwoFactor)
<p style="color:#53606d;font-size:14px">{{ $address('Für deine Rollen ist eine zusätzliche Anmeldemethode vorgeschrieben (Authenticator-App oder Passkey). Nach der ersten Anmeldung wirst du durch die Einrichtung geführt.', 'Für Ihre Rollen ist eine zusätzliche Anmeldemethode vorgeschrieben (Authenticator-App oder Passkey). Nach der ersten Anmeldung werden Sie durch die Einrichtung geführt.') }}</p>
@endif
<p style="color:#53606d;font-size:14px">Der Link ist {{ $validHours }} Stunden gültig und kann einmal verwendet werden. {{ $address('Ist er abgelaufen, wende dich an die Administration oder nutze auf der Anmeldeseite „Passwort vergessen“.', 'Ist er abgelaufen, wenden Sie sich an die Administration oder nutzen Sie auf der Anmeldeseite „Passwort vergessen“.') }}</p>
<p style="margin-bottom:0;color:#53606d;font-size:13px">{{ $address('Falls du kein Benutzerkonto erwartet hast, kannst du diese Nachricht ignorieren.', 'Falls Sie kein Benutzerkonto erwartet haben, können Sie diese Nachricht ignorieren.') }}</p>
</x-mail.layout>
