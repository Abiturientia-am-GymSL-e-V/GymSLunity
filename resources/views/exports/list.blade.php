<!doctype html>
<html lang="de"><head><meta charset="utf-8"><title>{{ $title }}</title>
<style>
@page { size: A4 landscape; margin: 12mm; }
body { font-family: 'DejaVu Sans', sans-serif; color: #182b40; font-size: 9px; }
h1 { font-size: 19px; margin-bottom: 4px; } p { color: #526577; }
.report-header { display: table; width: 100%; margin-bottom: 4px; }
.report-heading, .report-brand { display: table-cell; vertical-align: middle; }
.report-brand { width: 170px; text-align: right; }
.report-logo { display: inline-block; max-width: 160px; max-height: 42px; width: auto; height: auto; }
table { border-collapse: collapse; width: 100%; table-layout: fixed; }
th, td { border: 1px solid #d5dde5; padding: 5px; vertical-align: top; word-wrap: break-word; }
th { background: #eef2f6; text-align: left; } thead { display: table-header-group; }
.band { page-break-before: always; } .toolbar { margin-bottom: 16px; font-size: 14px; }
button { padding: 9px 15px; cursor: pointer; }
@media print { .toolbar { display: none; } }
</style></head><body>
@if (!$pdf)<div class="toolbar"><button type="button" onclick="window.print()">Drucken</button> · {{ count($rows) }} Mitglieder · Sichtbare Tabellenspalten</div>@endif
@foreach(array_chunk(array_keys($headers), 7) as $band => $columns)
@php if ($band > 0 && !in_array(0, $columns, true)) array_unshift($columns, 0); @endphp
<div @class(['band' => $band > 0])><div class="report-header"><div class="report-heading"><h1>{{ $title }}</h1></div>@if($logo)<div class="report-brand"><img class="report-logo" src="{{ $logo }}" alt="Vereinslogo"></div>@endif</div><p>Stand {{ $printedAt->format('d.m.Y H:i T') }} · {{ count($rows) }} Mitglieder @if($band > 0) · Weitere Spalten @endif</p>
<table><thead><tr>@foreach($columns as $column)<th>{{ $headers[$column] }}</th>@endforeach</tr></thead><tbody>
@forelse($rows as $row)<tr>@foreach($columns as $column)<td>{{ $row[$column] }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($columns) }}">Keine Mitglieder für diese Auswahl.</td></tr>@endforelse
</tbody></table></div>
@endforeach
</body></html>
