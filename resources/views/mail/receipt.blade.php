<x-mail.layout
    :club-name="$clubName"
    :logo-url="$logoUrl"
    :title="'Quittung '.$receipt->receipt_number"
    :preheader="'Quittung '.$receipt->receipt_number.' als PDF im Anhang'"
>
<p style="margin-top:0">Guten Tag,</p>
<p>im Anhang {{ \App\Support\FormOfAddress::choose('erhältst du', 'erhalten Sie') }} {{ $edition === 'original' ? 'das Original' : 'die Kopie' }} der Quittung {{ $receipt->receipt_number }}.</p>
<p>Bei Rückfragen {{ \App\Support\FormOfAddress::choose('wende dich', 'wenden Sie sich') }} bitte an {{ $receipt->snapshot['club']['name'] ?? 'den Verein' }} ({{ $receipt->snapshot['club']['email'] ?? '' }}).</p>
<p style="margin-bottom:0">Freundliche Grüße<br>{{ $clubName }}</p>
</x-mail.layout>
