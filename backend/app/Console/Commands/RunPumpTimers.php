<?php

namespace App\Console\Commands;

use App\Services\ConnectionConfiguration;
use App\Services\PumpControl;
use Illuminate\Console\Command;

class RunPumpTimers extends Command
{
    protected $signature = 'pumps:work {--once : Process due stops once} {--max-seconds=3600 : Restart after this lifetime}';

    protected $description = 'Process durable pump stop timers without repeated application bootstraps';

    public function handle(PumpControl $pumps, ConnectionConfiguration $connections): int
    {
        $seconds = filter_var($this->option('max-seconds'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 86400]]);
        if ($seconds === false) {
            return self::INVALID;
        }
        $stop = false;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT] as $signal) {
                pcntl_signal($signal, function () use (&$stop) {
                    $stop = true;
                });
            }
        }
        $end = hrtime(true) + $seconds * 1_000_000_000;
        do {
            $tick = hrtime(true);
            $connections->apply();
            $pumps->stopDue();
            if ($this->option('once') || $stop || hrtime(true) >= $end) {
                break;
            }
            $sleep = min(1_000_000_000 - (hrtime(true) - $tick), $end - hrtime(true));
            if ($sleep > 0) {
                usleep((int) ($sleep / 1000));
            }
        } while (! $stop && hrtime(true) < $end);

        return self::SUCCESS;
    }
}
