@props([
    'clubName',
    'logoUrl' => null,
    'preheader' => null,
    'title' => null,
])
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $title ?: $clubName }}</title>
</head>
<body style="margin:0;background:#f4f6f8;color:#17212b;font-family:Arial,sans-serif">
@if ($preheader)
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">{{ $preheader }}</div>
@endif
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f6f8;padding:32px 16px">
<tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border:1px solid #e3e7eb;border-radius:16px;overflow:hidden">
<tr><td style="padding:28px 32px;border-bottom:1px solid #e3e7eb">
@if ($logoUrl)<img src="{{ $logoUrl }}" alt="" style="display:block;max-height:64px;max-width:180px;margin-bottom:14px">@endif
<div style="font-size:21px;font-weight:700">{{ $clubName }}</div>
</td></tr>
<tr><td style="padding:32px;font-size:16px;line-height:1.6">
{{ $slot }}
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
