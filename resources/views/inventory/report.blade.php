<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Inventarliste</title>
    @include('payments.report-styles')
</head>
<body>
    <div class="report-header">
        <div class="report-heading">
            <h1>{{ $club['name'] ?? config('app.name') }} · Inventarliste</h1>
        </div>

        @if ($logo)
            <div class="report-brand">
                <img class="report-logo" src="{{ $logo }}" alt="">
            </div>
        @endif
    </div>

    <p>
        Stand {{ $printedAt->format('d.m.Y H:i T') }}
        · {{ $items->count() }} Einträge
    </p>

    <table>
        <thead>
            <tr>
                <th>Inventar-Nr.</th>
                <th>Gegenstand</th>
                <th>Zuordnung</th>
                <th>Anschaffung</th>
                <th>Abschreibung</th>
                <th class="number">Restwert</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($items as $item)
                <tr>
                    <td>{{ $item->inventory_number }}</td>
                    <td>
                        {{ $item->name }}<br>
                        {{ $options['categories'][$item->category] }}
                        @if ($item->serial_number)
                            · S/N {{ $item->serial_number }}
                        @endif
                    </td>
                    <td>
                        {{ $item->location }}
                        @if ($item->responsible_person)
                            <br>{{ $item->responsible_person }}
                        @endif
                    </td>
                    <td>
                        {{ $item->acquisition_date->format('d.m.Y') }}<br>
                        {{ number_format($item->acquisition_cost_cents / 100, 2, ',', '.') }} €
                    </td>
                    <td>
                        {{ $options['depreciationMethods'][$item->depreciation_method] }}
                    </td>
                    <td class="number">
                        {{ number_format($item->bookValueCents() / 100, 2, ',', '.') }} €
                    </td>
                    <td>{{ $options['statuses'][$item->status] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        Keine Inventareinträge entsprechen den gewählten Filtern.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>