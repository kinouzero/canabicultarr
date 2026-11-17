<?php

namespace App\Console\Commands;

use App\Services\MeasurementRetention;
use Illuminate\Console\Command;

class RetainMeasurements extends Command
{
    protected $signature = 'measurements:retain';

    protected $description = 'Queue only an explicitly confirmed measurement retention policy';

    public function handle(MeasurementRetention $retention): int
    {
        $retention->schedule();

        return self::SUCCESS;
    }
}
