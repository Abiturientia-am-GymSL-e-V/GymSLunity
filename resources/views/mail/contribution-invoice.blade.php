<x-mail.layout
    :club-name="$clubName"
    :logo-url="$logoUrl"
    :title="'Beitragsrechnung '.$contribution->invoice_number"
    :preheader="$messageText"
>
<div style="white-space:pre-line">{{ $messageText }}</div>
<p style="margin:24px 0 0;color:#53606d;font-size:14px">Rechnung: <strong>{{ $contribution->invoice_number }}</strong></p>
</x-mail.layout>
