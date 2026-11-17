<?php

namespace App\Jobs;

use App\Exceptions\MeasurementException;
use App\Models\HomebridgeLink;
use App\Services\RemoteSensorCollection;
use App\Services\RemoteSensorCollector;
use App\Services\RemoteSyncSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class CollectRemoteDevice implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public int $uniqueFor = 600;

    public bool $failOnTimeout = true;

    public function __construct(public string $serviceId, public array $versions, public string $polledAt, public string $instanceKey) {}

    public function uniqueId(): string
    {
        return 'hb-device:'.$this->instanceKey.':'.$this->serviceId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->shared()->releaseAfter(15)->expireAfter(90)];
    }

    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(RemoteSensorCollection $collection): void
    {
        $at = CarbonImmutable::parse($this->polledAt);
        if ($at->lessThan(now()->subMinutes(10)) || count($this->versions) > 100) {
            return;
        }
        $client = $collection->client('hb', $at);
        if ($client->instanceKey() !== $this->instanceKey) {
            return;
        }
        $schedule = app(RemoteSyncSchedule::class);
        $links = $schedule->eligibleQuery($client)->whereKey(array_keys($this->versions))->where('instance_key', $client->instanceKey())->get()
            ->filter(fn ($link) => $link->version === $this->versions[$link->id] && str_starts_with($link->entity_id, $this->serviceId.':') && $schedule->due($client, $link));
        if ($links->isEmpty()) {
            return;
        }
        $client->prefetch($links->pluck('entity_id')->all());
        foreach ($links as $link) {
            $result = (new RemoteSensorCollector($client))->collect($link);
            if ($result['outcome'] === 'failed' && $result['code'] === 'measurements.errors.providerUnavailable') {
                throw new MeasurementException($result['code']);
            }
        }
    }

    public function failed(): void
    {
        foreach ($this->versions as $id => $version) {
            HomebridgeLink::whereKey($id)->where('version', $version)->where('instance_key', $this->instanceKey)->update(['last_attempt_at' => now(), 'last_error' => 'measurements.errors.providerUnavailable']);
        }
    }
}
