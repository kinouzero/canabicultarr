<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Exceptions\MeasurementException;
use App\Jobs\CollectRemoteDevice;
use App\Jobs\CollectRemoteSensor;
use App\Models\RemoteSensorLink;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class RemoteSensorCollection
{
    public function client(string $provider, ?CarbonImmutable $polledAt = null): RemoteSensorClient
    {
        return match ($provider) {
            'ha' => app(HomeAssistantClient::class),
            'hb' => app()->makeWith(HomebridgeClient::class, ['observedAt' => $polledAt]),
            default => throw new \LogicException('Unknown remote sensor provider.'),
        };
    }

    public function enqueue(RemoteSensorClient $client, RemoteSensorLink $link, ?User $actor = null): array
    {
        if ($actor) {
            $current = $actor->fresh();
            if (! $current || ! $current->is_active || $current->session_version !== $actor->session_version) {
                throw new AuthenticationException;
            }
            Gate::forUser($current)->authorize('integrations.manage');
            if (! app(LocationAccess::class)->record($current, $link)) {
                throw new AuthorizationException;
            }
        }
        $link->loadMissing('sensor.device');
        if (! $link->is_enabled || ! $link->sensor->is_enabled || $link->sensor->device->status === AssetStatus::Retired) {
            return ['outcome' => 'skipped', 'code' => 'measurements.errors.disabled'];
        }
        if (! $client->configured()) {
            throw new MeasurementException('measurements.errors.notConfigured');
        }
        try {
            CollectRemoteSensor::dispatch($client->prefix(), $link->id, $link->version, now()->utc()->toISOString(), $actor?->uuid, $actor?->session_version)->onQueue('integrations');
        } catch (Throwable) {
            Log::warning('measurement.collection_queue_failed', ['link_uuid' => $link->id]);
            throw new MeasurementException('measurements.errors.providerUnavailable');
        }

        return ['outcome' => 'queued', 'code' => null];
    }

    public function batch(string $provider): int
    {
        $client = $this->client($provider);
        $model = $client->linkModel();
        if (! $client->configured() || ! Schema::hasTable((new $model)->getTable())) {
            return 0;
        }
        $schedule = app(RemoteSyncSchedule::class);
        $settings = $schedule->configuration($provider);
        if (! $settings['enabled']) {
            return 0;
        }
        $before = CarbonImmutable::now('UTC')->subSeconds($settings['intervalSeconds'])->format('Y-m-d H:i:s.u');
        $links = $schedule->eligibleQuery($client)->where('instance_key', $client->instanceKey())->with('sensor.device')
            ->where(fn ($query) => $query->whereNull('last_attempt_at')->orWhere('last_attempt_at', '<=', $before))
            ->orderByRaw('CASE WHEN last_attempt_at IS NULL THEN 0 ELSE 1 END')->orderBy('last_attempt_at')->orderBy('id')->limit(100)->get();
        if ($provider === 'hb') {
            foreach ($links->groupBy(fn ($link) => explode(':', $link->entity_id)[0]) as $service => $group) {
                CollectRemoteDevice::dispatch($service, $group->pluck('version', 'id')->all(), now()->utc()->toISOString(), $client->instanceKey())->onQueue('integrations');
            }
        } else {
            foreach ($links as $link) {
                $this->enqueue($client, $link);
            }
        }

        return $links->count();
    }
}
