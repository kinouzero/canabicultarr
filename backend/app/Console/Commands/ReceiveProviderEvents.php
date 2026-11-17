<?php

namespace App\Console\Commands;

use App\Services\RemoteEventReceiver;
use Illuminate\Console\Command;

class ReceiveProviderEvents extends Command
{
    protected $signature = 'measurements:events {provider : ha or hb} {--seconds=55 : Session lifetime, 1–55 seconds}';

    protected $description = 'Receive provider measurement events without controlling devices';

    public function handle(RemoteEventReceiver $receiver): int
    {
        $provider = $this->argument('provider');
        $seconds = filter_var($this->option('seconds'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 55]]);
        if (! in_array($provider, ['ha', 'hb'], true) || $seconds === false) {
            $this->error('Invalid provider or session lifetime.');

            return self::INVALID;
        }
        $receiver->receive($provider, $seconds);

        return self::SUCCESS;
    }
}
