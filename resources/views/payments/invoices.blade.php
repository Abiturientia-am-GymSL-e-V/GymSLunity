<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Beitragsrechnungen</title>
    @include('payments.invoice-styles')
</head>
<body>
    @foreach ($contributions as $contribution)
        @include('payments.invoice-content', [
            'contribution' => $contribution,
            'member' => $contribution->account->member,
            'club' => $club,
            'logo' => $logo,
            'giroCode' => $giroCodes[$contribution->id] ?? null,
            'pageBreak' => ! $loop->last,
        ])
    @endforeach
</body>
</html>
