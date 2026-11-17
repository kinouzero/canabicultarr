<?php

namespace App\Services;

use App\Exceptions\ReportException;
use App\Models\MeasurementReportExport;
use App\Models\Site;
use App\Models\User;
use Closure;

class CultureOverviewExport
{
    public function generate(User $actor, MeasurementReportExport $export, Closure $checkpoint)
    {
        $report = app(CultureOverview::class)->read($actor, $export->criteria);
        $site = empty($export->criteria['site_id']) ? null : app(LocationAccess::class)->run($actor, fn () => Site::findOrFail($export->criteria['site_id'])->name);
        $translate = fn ($key) => __('culture_exports.'.$key, [], $export->locale);
        $number = fn ($value) => app(MeasurementReportPdf::class)->number($value, $export->locale);
        $date = fn ($value) => app(MeasurementReportPdf::class)->date($value, $export);
        $phase = function ($id) use ($translate) {
            return LifecycleDefinition::stage($id)['name'] ?? $translate('phases.'.$id);
        };
        $location = fn ($row) => $row ? implode(' / ', array_filter([$row['site']['name'] ?? null, $row['room']['name'] ?? null, $row['zone']['name'] ?? null])) : $translate('unassigned');
        $sections = [];
        if (in_array('summary', $export->criteria['sections'], true)) {
            $rows = [];
            foreach (['total', 'active', 'planned', 'archived', 'due', 'wateringDue', 'lifecycleDue', 'harvested', 'harvestWeightGrams'] as $key) {
                $rows[] = [$translate($key), $report['plants'][$key]];
            }
            $rows[] = [$translate('activeStrains'), count(array_filter($report['strains'], fn ($row) => $row['active'] > 0))];
            $sections[] = ['id' => 'summary', 'headers' => [$translate('metric'), $translate('value')], 'rows' => $rows];
        }
        if (in_array('stages', $export->criteria['sections'], true)) {
            $sections[] = ['id' => 'stages', 'headers' => [$translate('stage'), $translate('plants')], 'rows' => collect($report['plants']['byPhase'])->map(fn ($count, $id) => [$phase($id), $count])->values()->all()];
        }
        if (in_array('strains', $export->criteria['sections'], true)) {
            $sections[] = ['id' => 'strains', 'headers' => [$translate('strain'), $translate('active'), $translate('harvested'), $translate('harvestWeightGrams')], 'rows' => array_map(fn ($row) => [$row['name'], $row['active'], $row['harvested'], $row['harvestWeightGrams']], $report['strains'])];
        }
        if (in_array('activity', $export->criteria['sections'], true)) {
            $sections[] = ['id' => 'activity', 'headers' => [$translate('dayUtc'), $translate('waterings'), $translate('maintenance'), $translate('transitions')], 'rows' => array_map(fn ($row) => [$row['day'], $row['waterings'], $row['maintenance'], $row['transitions']], $report['activity'])];
        }
        if (in_array('locations', $export->criteria['sections'], true)) {
            $sections[] = ['id' => 'locations', 'headers' => [$translate('location'), $translate('active'), $translate('planned'), $translate('due')], 'rows' => array_map(fn ($row) => [$location($row['location']), $row['active'], $row['planned'], $row['due']], $report['locations'])];
        }
        if (in_array('upcoming', $export->criteria['sections'], true)) {
            $sections[] = ['id' => 'upcoming', 'headers' => [$translate('plant'), $translate('stage'), $translate('dueAt'), $translate('location')], 'rows' => array_map(fn ($row) => [$row['name'], $phase($row['phase']), $date($row['dueAt']), $location($row['location'])], $report['upcoming'])];
        }
        $total = array_sum(array_map(fn ($section) => count($section['rows']), $sections));
        if ($total > ($export->format === 'pdf' ? 500 : 5000)) {
            throw new ReportException('reportExports.errors.tooLarge');
        }
        $checkpoint($total, $total, 35);
        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($stream === false) {
            throw new ReportException('reportExports.errors.failed', 503);
        }
        try {
            if ($export->format === 'pdf') {
                $charts = [];
                foreach ($sections as $section) {
                    if (in_array($section['id'], ['stages', 'strains', 'activity'], true) && count($section['rows'])) {
                        $charts[$section['id']] = $this->chart($section['rows'], $section['id'] === 'activity');
                    }
                }
                $html = view('reports.culture', compact('report', 'export', 'sections', 'site', 'translate', 'number', 'date', 'charts'))->render();
                $pdf = app(MeasurementReportPdf::class)->renderHtml($html);
                if (fwrite($stream, $pdf) !== strlen($pdf)) {
                    throw new ReportException('reportExports.errors.failed', 503);
                }
                $html = view('reports.culture', compact('report', 'export', 'site', 'sections', 'charts', 'translate', 'number', 'date'))->render();
                $pdf = app(MeasurementReportPdf::class)->renderHtml($html);
                if (fwrite($stream, $pdf) !== strlen($pdf)) {
                    throw new ReportException('reportExports.errors.failed', 503);
                }
            } else {
                fwrite($stream, "\xEF\xBB\xBF");
                $write = function ($row) use ($stream) {
                    // Spreadsheet text must not be interpreted as a formula, even after leading whitespace.
                    $row = array_map(fn ($value) => is_string($value) && preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'".$value : $value, $row);
                    if (fputcsv($stream, $row, ',', '"', '', "\r\n") === false) {
                        throw new ReportException('reportExports.errors.failed', 503);
                    }
                };
                $write([$translate('title'), $export->display['siteTitle']]);
                $write([$translate('site'), $site ?? $translate('allSites')]);
                $write([$translate('generated'), $date($report['generatedAt'])]);
                $write([$translate('period'), $report['days'], $translate('dayUtc')]);
                $write([$translate('scope')]);
                foreach ($sections as $section) {
                    $write([]);
                    $write([$translate($section['id'])]);
                    $write($section['headers']);
                    foreach ($section['rows'] as $row) {
                        $write($row);
                    }
                }
            }
            $checkpoint($total, $total, 85);
            rewind($stream);

            return $stream;
        } catch (\Throwable $exception) {
            fclose($stream);
            throw $exception;
        }
    }

    private function chart(array $rows, bool $activity): string
    {
        // Numeric positions identify rows in the adjacent accessible data table.
        $rows = array_slice($rows, 0, $activity ? 30 : 10);
        $image = imagecreatetruecolor(1200, 360);
        try {
            $white = imagecolorallocate($image, 255, 255, 255);
            $ink = imagecolorallocate($image, 40, 60, 48);
            $colors = [imagecolorallocate($image, 45, 105, 69), imagecolorallocate($image, 52, 114, 172), imagecolorallocate($image, 195, 137, 35)];
            imagefill($image, 0, 0, $white);
            $maximum = max(1, ...array_map(fn ($row) => $activity ? max(array_slice($row, 1)) : (int) $row[1], $rows));
            $width = 1100 / max(1, count($rows));
            foreach ($rows as $i => $row) {
                foreach ($activity ? array_slice($row, 1) : [$row[1]] as $series => $value) {
                    $bar = $width * .7 / ($activity ? 3 : 1);
                    $x = (int) (50 + $i * $width + $series * $bar);
                    $height = (int) (max(0, (float) $value) / $maximum * 280);
                    if ($height > 0) {
                        imagefilledrectangle($image, $x, 310 - $height, (int) ($x + max(1, $bar - 2)), 310, $colors[$series]);
                    }
                }
                imagestring($image, 3, (int) (50 + $i * $width), 320, (string) ($i + 1), $ink);
            }
            ob_start();
            try {
                imagepng($image);
                $png = ob_get_contents();
            } finally {
                ob_end_clean();
            }

            return 'data:image/png;base64,'.base64_encode($png);
        } finally {
            imagedestroy($image);
        }
    }
}
