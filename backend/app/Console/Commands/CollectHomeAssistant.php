<?php

namespace App\Console\Commands;

use App\Services\RemoteSensorCollection;
use Illuminate\Console\Command;

class CollectHomeAssistant extends Command
{
    protected $signature = 'measurements:collect';

    protected $description = 'Queue at most 100 enabled Home Assistant sensor polls';

    public function handle(RemoteSensorCollection $collection): int
    {
        $this->line(json_encode(['scheduled' => $collection->batch('ha')]));

        return self::SUCCESS;
    }
}
