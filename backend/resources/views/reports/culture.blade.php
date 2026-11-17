<!doctype html>
<html lang="{{ $export->locale }}"><head><meta charset="utf-8"><title>{{ $translate('title') }}</title>
<style>
@page { margin: 16mm; } body { font: 10px/1.45 'DejaVu Sans', sans-serif; color: #243c2e; } h1 { font-size: 20px; margin: 0 0 5px; } h2 { font-size: 14px; margin: 20px 0 6px; } p { margin: 4px 0; } .meta { color: #526459; font-size: 9px; } table { width: 100%; border-collapse: collapse; table-layout: fixed; } th, td { text-align: left; border-bottom: 1px solid #dce3de; padding: 6px; overflow-wrap: anywhere; } th { background: #eef3ef; } thead { display: table-header-group; } tr { page-break-inside: avoid; } img { width: 100%; height: auto; } .chart { page-break-inside: avoid; } .index { width: 22px; } h2 { page-break-after: avoid; }
</style></head><body>
<h1>{{ $translate('title') }}</h1><p>{{ $export->display['siteTitle'] }}</p>
<p class="meta">{{ $translate('site') }} : {{ $site ?? $translate('allSites') }} · {{ $translate('period') }} : {{ $report['days'] }} {{ $translate('dayUtc') }}</p>
<p class="meta">{{ $translate('generated') }} : {{ $date($report['generatedAt']) }} ({{ $export->timezone }})</p>
<p class="meta">{{ $translate('scope') }}</p>
@foreach($sections as $section)
<h2>{{ $translate($section['id']) }}</h2>
@if(isset($charts[$section['id']]))
<div class="chart"><img src="{{ $charts[$section['id']] }}" alt="{{ $translate($section['id']) }}">
@if($section['id'] === 'activity')<p class="meta">{{ $translate('activityLegend') }}</p>@endif
<p class="meta">{{ $translate('chartHelp') }}</p></div>
@endif
@if(count($section['rows']))
<table><thead><tr><th class="index">#</th>@foreach($section['headers'] as $header)<th>{{ $header }}</th>@endforeach</tr></thead><tbody>
@foreach($section['rows'] as $index => $row)<tr><td>{{ $index + 1 }}</td>@foreach($row as $cell)<td>{{ is_int($cell) ? $number($cell) : $cell }}</td>@endforeach</tr>@endforeach
</tbody></table>
@else<p>{{ $translate('empty') }}</p>@endif
@endforeach
</body></html>
