<!doctype html>
<html lang="de">
<head><meta charset="utf-8"><title>{{ $renderedSubject }}</title><style>
.rich-content p { margin: 0 0 14px; }
.rich-content h1, .rich-content h2, .rich-content h3 { margin: 20px 0 10px; line-height: 1.25; }
.rich-content ul, .rich-content ol { margin: 8px 0 14px 24px; padding-left: 18px; }
.rich-content blockquote { margin: 14px 0; padding-left: 14px; border-left: 3px solid #dfe5eb; color: #526577; }
.rich-content img { display: block; max-width: 100%; height: auto; margin: 14px 0; }
.rich-content a { color: #1f5f99; text-decoration: underline; }
</style></head>
<body style="margin:0;background:#f4f6f8;color:#172b3f;font-family:Arial,sans-serif">
@php
    $inlineImage = 0;
    $emailBody = preg_replace_callback(
        '/src="data:image\/(png|jpeg|gif|webp);base64,([a-z0-9+\/=\r\n]+)"/i',
        function (array $match) use ($message, &$inlineImage): string {
            $contents = base64_decode(preg_replace('/\s+/', '', $match[2]) ?? '', true);
            if (!is_string($contents)) {
                return '';
            }
            $extension = strtolower($match[1]) === 'jpeg' ? 'jpg' : strtolower($match[1]);
            $source = $message->embedData($contents, 'inline-'.(++$inlineImage).'.'.$extension, 'image/'.strtolower($match[1]));
            return 'src="'.e($source).'"';
        },
        $renderedBody,
    ) ?? $renderedBody;
@endphp
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f6f8;padding:24px 12px">
<tr><td align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #dfe5eb;border-radius:10px">
<tr><td class="rich-content" style="padding:28px;font-size:15px;line-height:1.65">{!! $emailBody !!}</td></tr>
</table></td></tr></table>
</body>
</html>
