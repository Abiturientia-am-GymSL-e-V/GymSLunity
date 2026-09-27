<x-mail.layout
    :club-name="$clubName"
    :logo-url="$logoUrl"
    :title="'Zuwendungsbestätigung '.$certificate->certificate_number"
    :preheader="'Ihre Zuwendungsbestätigung '.$certificate->certificate_number"
>
<p style="margin-top:0">Guten Tag {{ $certificate->donation->donor_name }},</p>
<p>{{ \App\Support\FormOfAddress::choose('anbei erhältst du deine', 'anbei erhalten Sie Ihre') }} Zuwendungsbestätigung {{ $certificate->certificate_number }}.</p>
<p style="margin-bottom:0">Freundliche Grüße<br>{{ $clubName }}</p>
</x-mail.layout>
