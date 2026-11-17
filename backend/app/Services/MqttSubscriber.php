<?php

namespace App\Services;

use App\Models\MqttMeasurementSource;
use PhpMqtt\Client\MqttClient;

class MqttSubscriber
{
    public function __construct(private MqttConfiguration $configuration, private MqttMeasurementIngestion $ingestion) {}

    public function receive(int $seconds): int
    {
        if (! $this->configuration->ready()) {
            return 0;
        }
        $sources = MqttMeasurementSource::where('is_enabled', true)->where('instance_key', $this->configuration->instanceKey())->whereHas('sensor', fn ($q) => $q->where('is_enabled', true)->whereHas('device', fn ($a) => $a->where('status', '!=', 'retired')))->orderBy('id')->limit(101)->get();
        if ($sources->isEmpty()) {
            return 0;
        }
        if ($sources->count() > 100) {
            throw new \RuntimeException('MQTT source capacity exceeded.');
        }
        $settings = $this->configuration->connectionSettings();
        $client = $this->configuration->client();
        $count = 0;
        try {
            $client->connect($settings, true);
            foreach ($sources as $source) {
                $client->subscribe($source->topic, function ($topic, $payload) use ($source, &$count, $client) {
                    $this->ingestion->ingest($source->id, $source->version, $topic, $payload);
                    if (++$count >= 1000) {
                        $client->interrupt();
                    }
                }, 1);
            }
            $signature = $sources->map(fn ($source) => $source->id.':'.$source->version)->all();
            $lastCheck = 0.0;
            $client->registerLoopEventHandler(function (MqttClient $mqtt, float $elapsed) use ($seconds, $signature, &$lastCheck) {
                if ($elapsed - $lastCheck >= 2) {
                    $lastCheck = $elapsed;
                    $current = MqttMeasurementSource::where('is_enabled', true)->where('instance_key', $this->configuration->instanceKey())->whereHas('sensor', fn ($q) => $q->where('is_enabled', true)->whereHas('device', fn ($a) => $a->where('status', '!=', 'retired')))->orderBy('id')->limit(101)->get()->map(fn ($source) => $source->id.':'.$source->version)->all();
                    if ($signature !== $current) {
                        $mqtt->interrupt();
                    }
                }
                if ($elapsed >= $seconds) {
                    $mqtt->interrupt();
                }
            });
            $client->loop(true);
        } finally {
            if ($client->isConnected()) {
                $client->disconnect();
            }
        }

        return $count;
    }
}
