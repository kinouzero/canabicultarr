<?php

namespace App\Console\Commands;

use App\Services\MqttConfiguration;
use App\Services\MqttSubscriber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ReceiveMqttMeasurements extends Command
{
    protected $signature = 'measurements:mqtt {--seconds=300 : Worker lifetime, 1–3600 seconds}';

    protected $description = 'Subscribe to explicitly configured MQTT measurement topics without publishing';

    public function handle(MqttConfiguration $configuration, MqttSubscriber $subscriber): int
    {
        $seconds = filter_var($this->option('seconds'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3600]]);
        if ($seconds === false) {
            $this->error('Invalid worker lifetime.');

            return self::INVALID;
        }
        if (! $configuration->ready()) {
            $this->info('MQTT reception is disabled or not configured.');

            return self::SUCCESS;
        }
        $lock = Cache::lock('measurements:mqtt', $seconds + 60);
        if (! $lock->get()) {
            $this->info('MQTT receiver already running.');

            return self::SUCCESS;
        }
        try {
            $until = microtime(true) + $seconds;
            while (microtime(true) < $until) {
                try {
                    $subscriber->receive(max(1, min(300, (int) ceil($until - microtime(true)))));
                } catch (\Throwable) {
                    Log::warning('mqtt_receiver.connection_failed');
                }
                if (microtime(true) < $until) {
                    usleep(1000000);
                }
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
