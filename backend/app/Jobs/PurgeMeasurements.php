<?php

namespace App\Jobs;

use App\Models\MeasurementPurgeRun;
use App\Services\MeasurementRetention;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class PurgeMeasurements implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public int $uniqueFor = 600;

    public function __construct(public string $runId) {}

    public function uniqueId(): string
    {
        return $this->runId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('measurement-retention'))->shared()->releaseAfter(15)->expireAfter(90)];
    }

    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(MeasurementRetention $retention): void
    {
        if ($retention->batch($this->runId)) {
            self::dispatch($this->runId)->onQueue('default');
        }
    }

    public function failed(?Throwable $exception): void
    {
        MeasurementPurgeRun::whereKey($this->runId)->whereIn('status', ['queued', 'running'])->update(['status' => 'failed', 'error_code' => 'retention.errors.failed', 'completed_at' => now()]);
    }
}
