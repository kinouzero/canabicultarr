<!doctype html>
<html lang="{{ $export->locale }}">
<head><meta charset="utf-8"><title>{{ $translate('title') }} — {{ $report['sensor']->name }}</title>
<style>
@page { margin: 34px 38px 42px; } body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; background: #fff; color: #243c2c; line-height: 1.45; } h1 { font-size: 20px; margin: 4px 0 8px; } h2 { font-size: 10pt; margin: 18px 0 8px; } p { margin: 5px 0; } .muted { color: #5c7163; } .brand { text-transform: uppercase; font-size: 9px; letter-spacing: 1px; } table { width: 100%; border-collapse: collapse; } td { vertical-align: top; padding: 6px 8px; border-bottom: 1px solid #e0e7e2; } .stats td { width: 50%; background: #f3f6f2; } strong { font-weight: bold; } .number { font-size: 16px; } img { width: 100%; } .avoid-break { page-break-inside: avoid; } .footnote { margin-top: 12px; font-size: 7pt; color: #5c7163; }
</style></head>
<body>
<p class="brand">{{ $export->display['siteTitle'] }}</p>
<h1>{{ $translate('title') }}</h1>
<p><strong>{{ $report['sensor']->name }}</strong> · {{ $report['sensor']->measurementType->name }} @if($report['sensor']->measurementType->unit) ({{ $report['sensor']->measurementType->unit }}) @endif</p>
<p class="muted">{{ $report['sensor']->device->name }} · {{ $translate('channel') }} {{ $report['sensor']->channel_key }}</p>
<table><tr><td>{{ $translate('from') }}<br><strong>{{ $date($report['window']['from']) }}</strong></td><td>{{ $translate('to') }}<br><strong>{{ $date($report['window']['to']) }}</strong></td></tr></table>
<p class="muted">{{ $translate('timezone') }} : {{ $export->timezone }} · {{ $translate('asOf') }} : {{ $date($report['window']['asOf']) }}</p>
<h2>{{ $translate('statistics') }}</h2>
<table class="stats">
@foreach(array_chunk(['count', 'minimum', 'mean', 'maximum'], 2) as $keys)<tr>
@foreach($keys as $key)
<td>{{ $translate($key) }}<br><strong class="number">{{ $number($report['statistics'][$key]) }}</strong></td>
@endforeach
</tr>@endforeach</table>
<div class="avoid-break"><h2>{{ $translate('chart') }}</h2>
@if($chart)
<img src="{{ $chart }}" alt="{{ $translate('chart') }}">
<p class="muted">{{ $date($report['window']['from']) }} → {{ $date($report['window']['to']) }}</p>
<p>{{ $translate('legend') }} · {{ $translate($report['window']['groupBy']) }} · {{ $report['sensor']->measurementType->unit }}</p>
@else
<p>{{ $translate('empty') }}</p>
@endif
</div>
<h2>{{ $translate('gaps') }}</h2>
<table><tr><td>{{ $translate('largestGap') }}<br><strong>{{ $number($report['statistics']['largestGapSeconds']) }} {{ $translate('seconds') }}</strong></td><td>{{ $translate('gapCount') }}<br><strong>{{ $number($report['statistics']['gapCount']) }}</strong></td><td>{{ $translate('gapThreshold') }}<br><strong>{{ $number($report['statistics']['gapThresholdSeconds']) }} {{ $translate('seconds') }}</strong></td></tr></table>
<h2>{{ $translate('alerts') }}</h2>
<table><tr>@foreach(['overlapping', 'opened', 'resolved', 'activeAtEnd'] as $key)<td>{{ $translate($key) }}<br><strong>{{ $number($report['alerts'][$key]) }}</strong></td>@endforeach</tr></table>
<p class="footnote">{{ $translate('scope') }}</p>
<p class="footnote">{{ $translate('generated') }} {{ $date(now()->utc()->toISOString()) }} · {{ $export->id }}</p>
</body></html>
