<!doctype html>
<html lang="de"><head><meta charset="utf-8"><title>Karteiblatt Mitglied {{ $member->member_number }}</title>
<style>
@page { size: A4 portrait; margin: 16mm; }
body { font-family: 'DejaVu Sans', sans-serif; color: #182b40; font-size: 10px; }
h1 { font-size: 20px; margin-bottom: 3px; } h2 { font-size: 14px; margin: 20px 0 6px; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; }
.report-header { display: table; width: 100%; margin-bottom: 3px; }
.report-heading, .report-brand { display: table-cell; vertical-align: middle; }
.report-brand { width: 170px; text-align: right; }
.report-logo { display: inline-block; max-width: 160px; max-height: 46px; width: auto; height: auto; }
p { line-height: 1.45; } .muted { color: #526577; } .toolbar { margin-bottom: 16px; } button { padding: 9px 15px; cursor: pointer; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
th,td { border-bottom: 1px solid #e2e8f0; padding: 5px 3px; vertical-align: top; overflow-wrap: anywhere; word-wrap: break-word; }
th { text-align: left; background: #f1f5f9; } .field-label { width: 38%; color: #526577; }
.item { page-break-inside: avoid; } .change { page-break-inside: avoid; border: 1px solid #e2e8f0; padding: 8px; margin-top: 8px; }
@media print { .toolbar { display: none; } }
</style></head><body>
@unless($pdf)<div class="toolbar"><button type="button" onclick="window.print()">Drucken</button> · <a href="{{ route('members.card', ['member' => $member->member_number, 'format' => 'pdf']) }}">PDF herunterladen</a></div>@endunless
<div class="report-header"><div class="report-heading"><h1>{{ $club['name'] ?? config('app.name') }} · Karteiblatt</h1></div>@if($logo)<div class="report-brand"><img class="report-logo" src="{{ $logo }}" alt="Vereinslogo"></div>@endif</div>
<p class="muted">{{ $member->first_name }} {{ $member->middle_name }} {{ $member->last_name }} · Mitglied Nr. {{ $member->member_number }} · Stand {{ $printedAt->format('d.m.Y H:i T') }}</p>
<p class="muted">Datensatz {{ $member->id }} · Version {{ $member->lock_version }} · Angelegt {{ $member->created_at?->setTimezone($timezone)->format('d.m.Y H:i T') }} · Zuletzt geändert {{ $member->updated_at?->setTimezone($timezone)->format('d.m.Y H:i T') }}</p>
@foreach($sections as $section)
<h2>{{ $section['title'] }}</h2><table><tbody>@foreach($section['rows'] as $row)<tr class="item"><td class="field-label">{{ $row['label'] }}</td><td>{{ $row['value'] }}</td></tr>@endforeach</tbody></table>
@endforeach
<h2>Dokumente</h2>
@forelse($documents as $document)
<p>{{ $document->kind === 'application' ? 'Mitgliedsantrag' : 'SEPA-Mandat'.($document->mandate_reference ? ' '.$document->mandate_reference : '') }} · {{ $document->submitted_online ? 'online eingereicht' : 'hinterlegt' }} · {{ $document->created_at }}@if($document->kind === 'sepa') · {{ $document->revoked_at ? 'widerrufen' : 'nicht widerrufen' }}@endif
@unless($pdf) · <a href="{{ $document->kind === 'sepa' ? route('members.mandates.document', ['member' => $member->member_number, 'document' => $document->id]) : route('members.document', ['member' => $member->member_number, 'kind' => $document->kind]) }}">Originaldokument herunterladen</a>@endunless</p>
@empty<p>Keine Dokumente hinterlegt.</p>@endforelse
<h2>Änderungshistorie</h2>
@forelse($changes as $change)<div class="change"><strong>{{ $change['date'] }} · Version {{ $change['version'] }} · {{ $change['actor'] }}</strong><table><thead><tr><th>Feld</th><th>Vorher</th><th>Nachher</th></tr></thead><tbody>@foreach($change['rows'] as $row)<tr><td>{{ $row['label'] }}</td><td>{{ $row['before'] }}</td><td>{{ $row['after'] }}</td></tr>@endforeach</tbody></table></div>
@empty<p>Keine Änderungen protokolliert.</p>@endforelse
<p class="muted">Dieses Karteiblatt enthält die in der Mitgliederverwaltung gespeicherten Angaben und den verfügbaren Änderungsverlauf. PDF-Dokumente sind separat herunterzuladen.</p>
</body></html>
