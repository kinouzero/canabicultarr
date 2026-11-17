<?php

namespace App\Console\Commands;

use App\Services\MeasurementReportExports;
use Illuminate\Console\Command;

class CleanupReportExports extends Command
{
    protected $signature = 'reports:cleanup-exports';

    protected $description = 'Remove expired private report files in bounded batches';

    public function handle(MeasurementReportExports $exports): int
    {
        $exports->cleanup();

        return self::SUCCESS;
    }
}
