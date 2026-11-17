<?php

namespace App\Services;

use App\Jobs\StoreRemoteSensorEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

class RemoteProviderEvents
{
    public function group(string $provider): string
    {
        return match ($provider) {
            'ha' => 'home_assistant', 'hb' => 'homebridge', default => throw new \LogicException('Unknown remote provider.')
        };
    }

    public function enabled(string $provider): bool
    {
        return filter_var(config($this->group($provider).'.events_enabled', false), FILTER_VALIDATE_BOOLEAN)
            && app(RemoteSyncSchedule::class)->configuration($provider)['enabled']
            && app(RemoteSensorCollection::class)->client($provider)->configured();
    }

    public function fingerprint(string $provider): string
    {
        $group = $this->group($provider);

        return hash_hmac('sha256', json_encode(array_map(fn ($key) => config($group.'.'.$key), ['url', 'token', 'username', 'password', 'events_enabled', 'sync_enabled']), JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function accept(string $provider, array $state, string $fingerprint): bool
    {
        if (! $this->enabled($provider) || ! hash_equals($this->fingerprint($provider), $fingerprint)) {
            return false;
        }
        $client = app(RemoteSensorCollection::class)->client($provider);
        $entity = $state['entity_id'] ?? null;
        if (! $client::validEntity($entity) || ! is_scalar($state['state'] ?? null) || strlen((string) $state['state']) > 64 || ! is_string($state['last_reported'] ?? $state['last_updated'] ?? null)) {
            return false;
        }
        $unit = $state['attributes']['unit_of_measurement'] ?? null;
        if ($unit !== null && (! is_string($unit) || mb_strlen($unit) > 24)) {
            return false;
        }
        $time = $state['last_reported'] ?? $state['last_updated'];
        if (strlen($time) > 40) {
            return false;
        }
        $quality = $state['attributes']['canabicultarr_quality'] ?? null;
        if ($quality !== null && (! is_string($quality) || strlen($quality) > 16)) {
            return false;
        }
        $link = app(RemoteSyncSchedule::class)->eligibleQuery($client)->where('instance_key', $client->instanceKey())->where('entity_id', $entity)->first();
        if (! $link || Queue::connection()->size('integrations') >= 2000) {
            return false;
        }
        $sample = ['entity_id' => $entity, 'state' => $state['state'], 'attributes' => ['unit_of_measurement' => $unit, 'canabicultarr_quality' => $quality, 'restored' => ($state['attributes']['restored'] ?? false) === true], 'last_reported' => $time];
        StoreRemoteSensorEvent::dispatch($provider, $link->id, $link->version, $fingerprint, $sample, now()->utc()->toISOString())->onQueue('integrations');

        return true;
    }

    public function diagnostic(string $provider, string $state): void
    {
        Cache::put('remote-events:'.$provider.':status', ['state' => $state, 'at' => now()->utc()->toISOString()], 120);
    }
}
