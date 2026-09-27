<!doctype html>
<html lang="de"><head><meta charset="utf-8"><style>
@page { margin:26px 30px; } body { font-family:DejaVu Sans,sans-serif; color:#111827; font-size:8.5pt; }
.head { display:table; width:100%; margin-bottom:14px; }.head>div{display:table-cell;vertical-align:middle}.logo{max-height:42px;max-width:150px}h1{font-size:17pt;margin:0 0 4px}.muted{color:#6b7280}
table{width:100%;border-collapse:collapse;table-layout:fixed}th,td{border:1px solid #9ca3af;padding:7px 6px;text-align:left;vertical-align:top;overflow-wrap:anywhere}th{background:#f3f4f6;font-weight:700}td{height:22px}.footer{margin-top:10px;color:#6b7280;font-size:7.5pt}
</style></head><body>
<div class="head"><div><h1>{{ $title }}</h1><div class="muted">{{ $clubName }}@if($event_date) · {{ \Illuminate\Support\Carbon::parse($event_date)->format('d.m.Y') }}@endif · {{ count($rows) }} Personen</div></div>@if($logo)<div style="text-align:right"><img class="logo" src="{{ $logo }}"></div>@endif</div>
<table><thead><tr>@foreach($headers as $header)<th>{{ $header }}</th>@endforeach</tr></thead><tbody>@foreach($rows as $row)<tr>@foreach($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach</tbody></table>
<div class="footer">Erstellt am {{ $printedAt->format('d.m.Y H:i') }} Uhr.</div>
</body></html>
