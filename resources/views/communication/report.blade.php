<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Kommunikationsbericht</title>
    @include('payments.report-styles')
    <style>
        .message { margin: 4mm 0 6mm; padding: 4mm; border: 1px solid #d8dee9; }
        .message img { max-width: 100%; }
    </style>
</head>
<body>
    <div class="report-header">
        <div class="report-heading"><h1>{{ $club['name'] ?? config('app.name') }} · Kommunikationsbericht</h1></div>
        @if ($logo)<div class="report-brand"><img class="report-logo" src="{{ $logo }}" alt=""></div>@endif
    </div>
    <p>
        {{ match ($campaign->kind) { 'mail' => 'Serien-E-Mail', 'welcome' => 'Willkommensmail', default => 'Serienbrief ('.strtoupper((string) $campaign->format).')' } }}
        vom {{ $createdAt }} · erstellt von {{ $campaign->created_by_name }} ·
        {{ $campaign->recipient_count }} Empfänger, {{ $campaign->skipped_count }} übersprungen,
        {{ $campaign->success_count }} erfolgreich, {{ $campaign->failure_count }} fehlgeschlagen ·
        Stand {{ $printedAt }}
    </p>
    <h2>Betreff: {{ $campaign->subject }}</h2>
    <p>Nachricht (Vorlage mit Platzhaltern, vor der Personalisierung):</p>
    {{-- Sanitized rich text (see RichTextSanitizer) or escaped plain text of welcome mails. --}}
    <div class="message">{!! $body !!}</div>
    @if (! empty($campaign->attachments))
        <p>Anhänge: {{ collect($campaign->attachments)->pluck('name')->join(', ') }}</p>
    @endif
    <h2>Empfängerliste</h2>
    <table>
        <thead><tr><th>Nr.</th><th>Name</th><th>E-Mail</th><th>Status</th><th>Hinweis</th></tr></thead>
        <tbody>
        @forelse ($deliveries as $delivery)
            <tr>
                <td>{{ $delivery->member_number }}</td>
                <td>{{ $delivery->recipient_name }}</td>
                <td>{{ $delivery->recipient_email ?: '–' }}</td>
                <td>{{ match ($delivery->status) { 'sent' => 'Versendet', 'failed' => 'Fehlgeschlagen', 'generated' => 'Erstellt', 'skipped' => 'Übersprungen', default => 'Ausstehend' } }}</td>
                <td>{{ $delivery->error ?: '' }}</td>
            </tr>
        @empty
            <tr><td colspan="5">Keine Empfänger erfasst.</td></tr>
        @endforelse
        </tbody>
    </table>
</body>
</html>
