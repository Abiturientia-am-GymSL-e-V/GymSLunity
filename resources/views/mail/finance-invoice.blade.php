<x-mail.layout
    :club-name="$clubName"
    :logo-url="$logoUrl"
    :title="$documentLabel.' '.$invoice->invoice_number"
    :preheader="$documentLabel.' '.$invoice->invoice_number.' von '.$clubName"
>
<p style="margin-top:0">Guten Tag,</p>
<p>im Anhang {{ \App\Support\FormOfAddress::choose('erhältst du', 'erhalten Sie') }} die {{ $documentLabel }} <strong>{{ $invoice->invoice_number }}</strong> als PDF und als strukturierte XRechnung.</p>
@if ($invoice->document_type === 'cancellation')
    @if (($invoice->snapshot['cancellation_scope'] ?? 'full') === 'partial')
<p>Dieser Beleg storniert ausgewählte Positionen aus der Rechnung <strong>{{ $invoice->snapshot['original_invoice']['invoice_number'] }}</strong>.</p>
    @else
<p>Dieser Beleg storniert die Rechnung <strong>{{ $invoice->snapshot['original_invoice']['invoice_number'] }}</strong> vollständig.</p>
    @endif
@endif
<p>Bei Rückfragen {{ \App\Support\FormOfAddress::choose('wende dich', 'wenden Sie sich') }} bitte an {{ $invoice->snapshot['seller']['name'] }} ({{ $invoice->snapshot['seller']['email'] }}).</p>
<p style="margin-bottom:0">Freundliche Grüße<br>{{ $clubName }}</p>
</x-mail.layout>
