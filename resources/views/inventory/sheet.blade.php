<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Inventarblatt {{ $inventoryItem->inventory_number }}</title>
    @include('payments.report-styles')
    <style>
        .details { width: 100%; border-collapse: collapse; margin-top: 18px; }
        .details th { width: 30%; text-align: left; vertical-align: top; }
        .details td, .details th { padding: 8px 10px; border-bottom: 1px solid #d8dde3; }
        .description { white-space: pre-wrap; }
    </style>
</head>
<body>
    <div class="report-header">
        <div class="report-heading">
            <h1>{{ $club['name'] ?? config('app.name') }} · Inventarblatt</h1>
            <p>{{ $inventoryItem->inventory_number }} · {{ $inventoryItem->name }}</p>
        </div>
        @if ($logo)
            <div class="report-brand"><img class="report-logo" src="{{ $logo }}" alt=""></div>
        @endif
    </div>

    <table class="details">
        <tbody>
            <tr><th>Inventarnummer</th><td>{{ $inventoryItem->inventory_number }}</td></tr>
            <tr><th>Bezeichnung</th><td>{{ $inventoryItem->name }}</td></tr>
            <tr><th>Kategorie</th><td>{{ $options['categories'][$inventoryItem->category] }}</td></tr>
            <tr><th>Status</th><td>{{ $options['statuses'][$inventoryItem->status] }}</td></tr>
            <tr><th>Hersteller / Modell</th><td>{{ collect([$inventoryItem->manufacturer, $inventoryItem->model])->filter()->join(' · ') ?: '–' }}</td></tr>
            <tr><th>Seriennummer</th><td>{{ $inventoryItem->serial_number ?: '–' }}</td></tr>
            <tr><th>Standort</th><td>{{ $inventoryItem->location }}</td></tr>
            <tr><th>Verantwortlich</th><td>{{ $inventoryItem->responsible_person ?: '–' }}</td></tr>
            <tr><th>Zugang</th><td>{{ $options['acquisitionTypes'][$inventoryItem->acquisition_type] }} am {{ $inventoryItem->acquisition_date->format('d.m.Y') }}</td></tr>
            <tr><th>Anschaffungswert</th><td>{{ number_format($inventoryItem->acquisition_cost_cents / 100, 2, ',', '.') }} €</td></tr>
            <tr><th>Beleg / Referenz</th><td>{{ $inventoryItem->document_reference ?: '–' }}</td></tr>
            <tr>
                <th>Abschreibung</th>
                <td>
                    {{ $options['depreciationMethods'][$inventoryItem->depreciation_method] }}
                    @if ($inventoryItem->depreciation_method === 'linear')
                        · {{ $inventoryItem->useful_life_years }} Jahre
                        · {{ number_format($inventoryItem->annualDepreciationCents() / 100, 2, ',', '.') }} €/Jahr
                    @endif
                </td>
            </tr>
            <tr><th>{{ $inventoryItem->status === 'active' ? 'Aktueller Restwert' : 'Restwert bei Abgang' }}</th><td>{{ number_format($inventoryItem->bookValueCents() / 100, 2, ',', '.') }} €</td></tr>
            @if ($inventoryItem->description)
                <tr><th>Beschreibung / Zustand</th><td class="description">{{ $inventoryItem->description }}</td></tr>
            @endif
            @if ($inventoryItem->status !== 'active')
                <tr><th>Abgang</th><td>{{ $inventoryItem->disposed_at?->format('d.m.Y') }} · {{ $options['statuses'][$inventoryItem->status] }}</td></tr>
                <tr><th>Verkaufserlös</th><td>{{ $inventoryItem->disposal_proceeds_cents === null ? '–' : number_format($inventoryItem->disposal_proceeds_cents / 100, 2, ',', '.').' €' }}</td></tr>
                @if ($inventoryItem->disposal_note)
                    <tr><th>Abgangsvermerk</th><td class="description">{{ $inventoryItem->disposal_note }}</td></tr>
                @endif
            @endif
        </tbody>
    </table>

    <p>Erfasst durch {{ $inventoryItem->created_by_name }} · Ausdruck vom {{ $printedAt->format('d.m.Y H:i T') }}</p>
</body>
</html>
