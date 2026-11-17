<?php

namespace App\Jobs;

use App\Exceptions\MeasurementException;
use App\Models\User;
use App\Services\RemoteSensorCollection;
use App\Services\RemoteSensorCollector;
use App\Services\RemoteSyncSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class CollectRemoteSensor implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 600;

    public function __construct(public string $provider, public string $linkId, public int $version, public string $polledAt, public ?string $actorUuid = null, public ?int $actorVersion = null) {}

    public function uniqueId(): string
    {
        return $this->provider.':'.$this->linkId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('remote-sensor:'.$this->uniqueId()))->shared()->releaseAfter(15)->expireAfter(90)];
    }

    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(RemoteSensorCollection $collection): void
    {
        try {
            $polledAt = CarbonImmutable::parse($this->polledAt);
            if ($polledAt->lessThan(now()->subMinutes(10))) {
                return;
            }
            $client = $collection->client($this->provider, $polledAt);
            $link = $client->linkModel()::find($this->linkId);
            if (! $link || $link->version !== $this->version) {
                return;
            }
            $actor = $this->actorUuid ? User::where('uuid', $this->actorUuid)->first() : null;
            if ($this->actorUuid && (! $actor || ! $actor->is_active || $actor->session_version !== $this->actorVersion)) {
                return;
            }
            if ($this->actorUuid === null && ! app(RemoteSyncSchedule::class)->due($client, $link)) {
                return;
            }
            $result = (new RemoteSensorCollector($client))->collect($link, $actor);
        } catch (AuthorizationException|AuthenticationException) {
            return;
        } catch (Throwable) {
            throw new MeasurementException('measurements.errors.providerUnavailable');
        }
        if ($result['outcome'] === 'failed' && $result['code'] === 'measurements.errors.providerUnavailable') {
            throw new MeasurementException($result['code']);
        }
    }

    public function failed(?Throwable $error): void
    {
        try {
            $client = app(RemoteSensorCollection::class)->client($this->provider);
            $client->linkModel()::whereKey($this->linkId)->where('version', $this->version)
                ->update(['last_attempt_at' => now(), 'last_error' => 'measurements.errors.providerUnavailable']);
        } catch (Throwable) {
            Log::warning('measurement.collection_failure_state_unavailable', ['link_uuid' => $this->linkId]);
        }
    }
}
