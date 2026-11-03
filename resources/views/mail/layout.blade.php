<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title')</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2937;font-size:15px;line-height:1.5;">
{{-- Plain and mobile friendly: one column, no images, no tracking pixels, no external styles. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;">
<tr><td align="center" style="padding:16px 8px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:8px;border:1px solid #e5e7eb;">
<tr><td style="padding:20px 24px 8px;font-size:18px;font-weight:700;">{{ $store['name'] ?? $store }}</td></tr>
<tr><td style="padding:8px 24px 24px;">
@yield('body')
</td></tr>
<tr><td style="padding:16px 24px;border-top:1px solid #e5e7eb;font-size:13px;color:#6b7280;">
@yield('footer')
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
