@extends('mail.layout', ['heading' => $title, 'category' => __('mail.monitoring'), 'preview' => __('mail.alertIntro')])

@section('content')
<p style="margin:0 0 20px;font-size:15px;line-height:1.7;">{{ __('mail.alertIntro') }}</p>
<div style="padding:18px;background-color:#f2f5ef;border-radius:10px;color:#365344;font-size:14px;line-height:1.8;overflow-wrap:anywhere;">{!! nl2br(e($messageText)) !!}</div>
<p style="margin:20px 0 0;color:#62746a;font-size:13px;line-height:1.6;">{{ __('mail.alertHelp') }}</p>
@endsection
