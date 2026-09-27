<x-mail.layout
    :club-name="$clubName"
    :logo-url="$logoUrl"
    :title="'SEPA-Lastschriftmandat '.$mandate->mandate_reference"
    :preheader="'SEPA-Lastschriftmandat prüfen und unterzeichnen'"
>
<p style="margin-top:0">Guten Tag {{ $mandate->debtor_name }},</p>
@if ($mandate->status === 'signed')
<p>im Anhang {{ \App\Support\FormOfAddress::choose('erhältst du', 'erhalten Sie') }} das bereits unterzeichnete SEPA-Lastschriftmandat {{ $mandate->mandate_reference }}.</p>
@else
<p>im Anhang {{ \App\Support\FormOfAddress::choose('erhältst du', 'erhalten Sie') }} das SEPA-Lastschriftmandat {{ $mandate->mandate_reference }}. Es kann ausgedruckt und handschriftlich oder direkt online unterzeichnet werden.</p>
<p style="margin:24px 0"><a href="{{ $signingUrl }}" style="display:inline-block;padding:12px 18px;border-radius:8px;background:#111827;color:#ffffff;text-decoration:none;font-weight:600">Mandat online unterzeichnen</a></p>
@endif
<p style="margin-bottom:0">Freundliche Grüße<br>{{ $clubName }}</p>
</x-mail.layout>
