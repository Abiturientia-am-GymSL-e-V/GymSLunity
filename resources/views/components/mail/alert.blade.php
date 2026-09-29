@props([
    'type' => 'error',
    'title' => null,
])
@php
    // Fixed mail colors; application CSS tokens are not available in mail clients.
    $colors = match ($type) {
        'warning' => ['background' => '#fff7e6', 'border' => '#b54708', 'text' => '#7a2e0e'],
        'info' => ['background' => '#eef4fb', 'border' => '#1f5fa8', 'text' => '#17365d'],
        default => ['background' => '#fdecec', 'border' => '#d92d20', 'text' => '#7a1a12'],
    };
@endphp
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 24px">
<tr><td role="alert" style="padding:16px 18px;border:1px solid {{ $colors['border'] }};border-left-width:4px;border-radius:8px;background:{{ $colors['background'] }};color:{{ $colors['text'] }};font-size:15px;line-height:1.5">
@if ($title)
<div style="font-weight:700;margin-bottom:4px">{{ $title }}</div>
@endif
{{ $slot }}
</td></tr>
</table>
