<?php

namespace App\Console\Commands;

use App\Services\RemoteSensorCollection;
use Illuminate\Console\Command;

class CollectHomebridge extends Command
{
    protected $signature = 'measurements:collect-homebridge';

    protected $description = 'Queue at most 100 enabled Homebridge sensor polls';

    public function handle(RemoteSensorCollection $collection): int
    {
        $this->line(json_encode(['scheduled' => $collection->batch('hb')]));

        return self::SUCCESS;
    }
}
