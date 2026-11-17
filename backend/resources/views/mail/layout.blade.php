<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light"><title>{{ $heading }}</title></head>
<body style="margin:0;padding:0;background-color:#f2f5ef;color:#243a31;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;">
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">{{ $preview }}</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f2f5ef;">
<tr><td align="center" style="padding:32px 16px;">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;">
<tr><td style="padding:0 4px 20px;">
<table role="presentation" cellspacing="0" cellpadding="0"><tr>
<td width="52" style="vertical-align:middle;"><img src="{{ $message->embed(resource_path('images/mail-logo.png')) }}" width="44" height="44" alt="" style="display:block;border:0;border-radius:12px;"></td>
<td style="vertical-align:middle;"><span style="font-size:18px;font-weight:bold;color:#143e33;">Canabicultarr</span><br><span style="font-size:12px;line-height:20px;color:#62746a;">{{ __('mail.tagline') }}</span></td>
</tr></table>
</td></tr>
<tr><td style="padding:28px;background-color:#ffffff;border:1px solid #dce5da;border-top:4px solid #648b3f;border-radius:16px;">
<p style="margin:0 0 10px;color:#648b3f;font-size:11px;font-weight:bold;letter-spacing:1.2px;text-transform:uppercase;">{{ $category }}</p>
<h1 style="margin:0 0 18px;color:#143e33;font-size:25px;line-height:1.3;font-weight:bold;">{{ $heading }}</h1>
@yield('content')
</td></tr>
<tr><td align="center" style="padding:20px 16px;color:#62746a;font-size:12px;line-height:1.6;">{{ __('mail.footer') }}</td></tr>
</table>
</td></tr></table>
</body></html>
