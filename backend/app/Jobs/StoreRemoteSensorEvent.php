<?php

namespace App\Jobs;

use App\Services\RemoteProviderEvents;
use App\Services\RemoteSensorCollection;
use App\Services\RemoteSensorCollector;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class StoreRemoteSensorEvent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 600;

    public function __construct(public string $provider, public string $linkId, public int $version, public string $fingerprint, public array $state, public string $receivedAt) {}

    public function uniqueId(): string
    {
        return $this->provider.':'.$this->linkId.':'.hash('sha256', json_encode($this->state, JSON_THROW_ON_ERROR));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('remote-sensor:'.$this->provider.':'.$this->linkId))->shared()->releaseAfter(5)->expireAfter(90)];
    }

    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(RemoteProviderEvents $events, RemoteSensorCollection $collection): void
    {
        if (! $events->enabled($this->provider) || ! hash_equals($events->fingerprint($this->provider), $this->fingerprint) || CarbonImmutable::parse($this->receivedAt)->lessThan(now()->subMinutes(10))) {
            return;
        }
        $client = $collection->client($this->provider);
        $link = $client->linkModel()::find($this->linkId);
        if (! $link || $link->version !== $this->version || $link->entity_id !== ($this->state['entity_id'] ?? null)) {
            return;
        }
        (new RemoteSensorCollector($client))->collect($link, eventState: $this->state, receivedAt: CarbonImmutable::parse($this->receivedAt));
    }
}
