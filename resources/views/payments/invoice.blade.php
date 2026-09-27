<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ $contribution->invoice_number }}</title>
    @include('payments.invoice-styles')
</head>
<body>
    @if ($print)
        <div class="actions"><button type="button" onclick="window.print()">Drucken</button></div>
    @endif
    @include('payments.invoice-content', compact('contribution', 'member', 'club', 'logo', 'giroCode'))
</body>
</html>
