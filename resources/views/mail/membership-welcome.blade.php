<x-mail.layout
    :club-name="$clubName"
    :logo-url="$logoUrl"
    :title="$subjectLine"
    :preheader="$messageText"
>
<div style="white-space:pre-line">{{ $messageText }}</div>
@if($announcesWelcomeMail)
<p style="margin-bottom:0;color:#53606d;font-size:14px">{{ \App\Support\FormOfAddress::choose('Sobald deine Mitgliedschaft aktiv ist, erhältst du eine separate E-Mail mit Hinweisen zur Anmeldung und Nutzung des Mitgliederbereichs.', 'Sobald Ihre Mitgliedschaft aktiv ist, erhalten Sie eine separate E-Mail mit Hinweisen zur Anmeldung und Nutzung des Mitgliederbereichs.') }}</p>
@endif
</x-mail.layout>
