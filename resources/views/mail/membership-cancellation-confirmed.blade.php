<x-mail.layout
    :club-name="$clubName"
    :logo-url="$logoUrl"
    title="Kündigungsbestätigung"
    :preheader="'Die Mitgliedschaft endet zum '.$formattedExitDate.'.'"
>
<p style="margin:0 0 18px">Hallo {{ $memberName }},</p>
<p style="margin:0 0 18px">wir bestätigen {{ \App\Support\FormOfAddress::choose('deine', 'Ihre') }} Kündigung. {{ \App\Support\FormOfAddress::choose('Deine', 'Ihre') }} Mitgliedschaft endet zum <strong>{{ $formattedExitDate }}</strong>.</p>
<p style="margin:0">Bei Rückfragen {{ \App\Support\FormOfAddress::choose('wende dich', 'wenden Sie sich') }} bitte an die Vereinsverwaltung.</p>
</x-mail.layout>
