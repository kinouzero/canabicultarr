<?php

namespace App\Services;

use App\Exceptions\ReportException;
use App\Models\MeasurementReportExport;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use IntlDateFormatter;
use NumberFormatter;

class MeasurementReportPdf
{
    public function date(string $value, MeasurementReportExport $export): string
    {
        $date = CarbonImmutable::parse($value)->setTimezone($export->timezone);
        $format = $export->display['dateFormat'];
        $styles = ['localized-short' => IntlDateFormatter::SHORT, 'localized' => IntlDateFormatter::MEDIUM, 'localized-long' => IntlDateFormatter::LONG, 'localized-full' => IntlDateFormatter::FULL];
        $day = isset($styles[$format])
            ? (new IntlDateFormatter($export->locale, $styles[$format], IntlDateFormatter::NONE, $export->timezone))->format($date)
            : $date->format($format);

        return $day.' '.$date->format($export->display['timeFormat'] === '12' ? 'h:i A' : 'H:i');
    }

    public function number(string|int|null $value, string $locale): string
    {
        if ($value === null) {
            return '—';
        }
        $symbols = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $negative = str_starts_with((string) $value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim((string) $value, '-'), 2), 2, '');
        $fraction = rtrim($fraction, '0');
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', $symbols->getSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL), $whole);

        return ($negative && ($whole !== '0' || $fraction !== '') ? '-' : '').$grouped.($fraction !== '' ? $symbols->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL).$fraction : '');
    }

    public function html(array $report, MeasurementReportExport $export): string
    {
        return view('reports.measurements', ['report' => $report, 'export' => $export, 'chart' => $this->chart($report, $export->locale),
            'number' => fn (string|int|null $value) => $this->number($value, $export->locale),
            'date' => fn (string $value) => $this->date($value, $export), 'translate' => fn (string $key, array $values = []) => __('report_exports.'.$key, $values, $export->locale)])->render();
    }

    public function render(array $report, MeasurementReportExport $export): string
    {
        return $this->renderHtml($this->html($report, $export));
    }

    public function renderHtml(string $html): string
    {
        $cache = storage_path('framework/cache/report-pdf');
        File::ensureDirectoryExists($cache, 0700, true);
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setAllowedProtocols(['file://', 'data://']);
        $options->setChroot([base_path('vendor/dompdf'), $cache]);
        $options->setTempDir($cache);
        $options->setFontCache($cache);
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsFontSubsettingEnabled(true);
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();
        $pdf->getCanvas()->page_text(475, 810, '{PAGE_NUM} / {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('DejaVu Sans'), 8);
        $content = $pdf->output();
        if (strlen($content) > 16777216) {
            throw new ReportException('reportExports.errors.tooLarge');
        }

        return $content;
    }

    private function chart(array $report, string $locale): ?string
    {
        if ($report['statistics']['count'] === 0) {
            return null;
        }
        $buckets = $report['buckets'];
        if (count($buckets) > 745) {
            throw new ReportException('reportExports.errors.tooLarge');
        }
        $image = imagecreatetruecolor(1400, 420);
        try {
            $white = imagecolorallocate($image, 255, 255, 255);
            $ink = imagecolorallocate($image, 77, 96, 83);
            $grid = imagecolorallocate($image, 224, 231, 226);
            $line = imagecolorallocate($image, 45, 105, 69);
            $range = imagecolorallocate($image, 172, 199, 179);
            imagefill($image, 0, 0, $white);
            $minimum = (float) $report['statistics']['minimum'];
            $maximum = (float) $report['statistics']['maximum'];
            $padding = max(($maximum - $minimum) * .05, abs($maximum) * .001, .001);
            $minimum -= $padding;
            $maximum += $padding;
            $y = fn (float $value) => (int) round(375 - ($value - $minimum) / ($maximum - $minimum) * 350);
            for ($i = 0; $i <= 4; $i++) {
                $value = $minimum + ($maximum - $minimum) * $i / 4;
                imageline($image, 155, $y($value), 1380, $y($value), $grid);
                imagestring($image, 3, 0, $y($value) - 5, str_replace('.', $locale === 'fr' ? ',' : '.', sprintf('%.6g', $value)), $ink);
            }
            $from = CarbonImmutable::parse($report['window']['from'])->getTimestamp();
            $duration = max(1, CarbonImmutable::parse($report['window']['to'])->getTimestamp() - $from);
            $previous = null;
            foreach ($buckets as $bucket) {
                if ($bucket['mean'] === null) {
                    $previous = null;

                    continue;
                }
                $middle = (CarbonImmutable::parse($bucket['startAt'])->getTimestamp() + CarbonImmutable::parse($bucket['endAt'])->getTimestamp()) / 2;
                $x = (int) round(155 + ($middle - $from) / $duration * 1225);
                imageline($image, $x, $y((float) $bucket['minimum']), $x, $y((float) $bucket['maximum']), $range);
                $point = [$x, $y((float) $bucket['mean'])];
                imagesetthickness($image, 3);
                if ($previous !== null) {
                    imageline($image, $previous[0], $previous[1], $point[0], $point[1], $line);
                }
                imagefilledellipse($image, $point[0], $point[1], 5, 5, $line);
                $previous = $point;
            }
            ob_start();
            imagepng($image);
            $bytes = ob_get_clean();

            return 'data:image/png;base64,'.base64_encode($bytes);
        } finally {
            imagedestroy($image);
        }
    }
}
