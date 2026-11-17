@extends('mail.layout', ['heading' => __($copy.'.heading'), 'category' => __('mail.account'), 'preview' => __($copy.'.intro')])

@section('content')
<p style="margin:0 0 24px;font-size:15px;line-height:1.7;">{{ __($copy.'.intro') }}</p>
<table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 0 24px;"><tr><td bgcolor="#143e33" style="border-radius:10px;"><a href="{{ $actionUrl }}" style="display:inline-block;padding:14px 22px;border:1px solid #143e33;border-radius:10px;color:#ffffff;font-size:14px;font-weight:bold;text-decoration:none;">{{ __($copy.'.action') }}</a></td></tr></table>
<div style="padding:16px;background-color:#f2f5ef;border-radius:10px;">
<p style="margin:0;color:#365344;font-size:13px;line-height:1.6;">{{ __($copy.'.expiry', ['minutes' => $minutes ?? 60]) }}</p>
@if(!empty($details))
<p style="margin:10px 0 0;color:#62746a;font-size:12px;line-height:1.6;">{{ $details }}</p>
@endif
</div>
<p style="margin:20px 0;color:#62746a;font-size:13px;line-height:1.6;">{{ __($copy.'.ignore') }}</p>
<div style="padding-top:18px;border-top:1px solid #e5ebe2;">
<p style="margin:0 0 8px;color:#62746a;font-size:12px;line-height:1.6;">{{ __('mail.fallback') }}</p>
<a href="{{ $actionUrl }}" style="color:#365b43;font-size:12px;line-height:1.6;word-break:break-all;overflow-wrap:anywhere;">{{ $actionUrl }}</a>
</div>
@endsection
