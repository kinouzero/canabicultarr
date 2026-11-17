<?php

namespace App\Jobs;

use App\Models\MeasurementReportExport;
use App\Services\MeasurementReportExports;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class GenerateMeasurementReport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 600;

    public function __construct(public string $exportId) {}

    public function uniqueId(): string
    {
        return $this->exportId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('report-export:'.$this->exportId))->shared()->releaseAfter(90)->expireAfter(90)];
    }

    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(MeasurementReportExports $exports): void
    {
        $exports->generate($this->exportId);
    }

    public function failed(?Throwable $exception): void
    {
        MeasurementReportExport::whereKey($this->exportId)->whereIn('status', ['queued', 'processing'])->update(['status' => 'failed', 'error_code' => 'reportExports.errors.failed', 'completed_at' => now()]);
    }
}
