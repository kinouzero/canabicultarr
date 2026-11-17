<?php

namespace App\Services;

use App\Enums\AssetCategory;
use App\Enums\AssetStatus;
use App\Exceptions\AssetException;
use App\Exceptions\MeasurementException;
use App\Models\HomeAssistantLink;
use App\Models\HomebridgeLink;
use App\Models\RemoteSensorLink;
use App\Models\Sensor;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class SensorSwitchControl
{
    private function sources(Collection $sensors): array
    {
        $sources = [];
        foreach (['home_assistant' => [HomeAssistantClient::class, HomeAssistantLink::class], 'homebridge' => [HomebridgeClient::class, HomebridgeLink::class]] as $provider => [$clientClass, $model]) {
            $client = app($clientClass);
            if (! $client->configured()) {
                continue;
            }
            foreach ($model::whereIn('sensor_id', $sensors->pluck('id'))->where('is_enabled', true)->where('instance_key', $client->instanceKey())->get() as $link) {
                $sources[$link->sensor_id][] = ['provider' => $provider, 'client' => $client, 'link' => $link];
            }
        }

        return array_map(fn ($rows) => count($rows) === 1 ? $rows[0] : null, $sources);
    }

    public function describe(Collection $sensors): array
    {
        return $this->metadata($sensors->filter(fn ($sensor) => $sensor->measurementType->nature === 'binary'))['switches'];
    }

    public static function sourceRevision(RemoteSensorLink $link): string
    {
        return hash('sha256', implode('|', [$link->getTable(), $link->id, $link->version, $link->instance_key, $link->entity_id]));
    }

    public function metadata(Collection $sensors): array
    {
        $sensors = $sensors->unique('id')->values();
        if ($sensors->isEmpty()) {
            return ['switches' => [], 'sourceRevisions' => []];
        }
        $sources = $this->sources($sensors);
        $actor = app(LocationAccess::class)->actor();
        $result = [];
        $revisions = [];
        foreach ($sensors as $sensor) {
            $source = $sources[$sensor->id] ?? null;
            $revisions[$sensor->id] = $source ? self::sourceRevision($source['link']) : null;
            if (! $source || $sensor->measurementType->nature !== 'binary' || ! DeviceControlClient::validTarget($source['provider'], $source['link']->control_entity)) {
                continue;
            }
            $asset = $sensor->device;
            $pump = $asset->category === AssetCategory::Pump;
            $configuredPump = $pump && ($asset->pump_control['provider'] ?? null) === $source['provider'] && ($asset->pump_control['entity'] ?? null) === $source['link']->control_entity;
            $result[$sensor->id] = ['kind' => $pump ? 'pump' : 'switch', 'canControl' => $actor && $sensor->is_enabled && $asset->status === AssetStatus::Active && (! $pump || $configuredPump) && Gate::forUser($actor)->allows('update', $asset)];
        }

        return ['switches' => $result, 'sourceRevisions' => $revisions];
    }

    public function binding(User $actor, Sensor $sensor, bool $live = false): array
    {
        $sensor->loadMissing('device', 'measurementType');
        Gate::forUser($actor)->authorize('update', $sensor->device);
        $source = $this->sources($sensor->newCollection([$sensor]))[$sensor->id] ?? null;
        if (! $source || ! $sensor->is_enabled || $sensor->device->status !== AssetStatus::Active || $sensor->device->category === AssetCategory::Pump || $sensor->measurementType->nature !== 'binary'
            || ! DeviceControlClient::validTarget($source['provider'], $source['link']->control_entity)) {
            throw new AssetException('assets.power.unavailable', 422);
        }
        $state = null;
        if ($live) {
            try {
                $row = $source['client']->state($source['link']->entity_id);
            } catch (MeasurementException $exception) {
                throw new AssetException('assets.power.unavailable', $exception->errorCode === 'measurements.errors.entityMissing' ? 422 : 502);
            }
            if ($source['client']->controlEntity($row) !== $source['link']->control_entity || ! in_array($row['state'] ?? null, ['0', '1'], true)) {
                throw new AssetException('assets.power.unavailable', 422);
            }
            $state = $row['state'] === '1';
        }

        return ['isOn' => $state, 'control' => ['provider' => $source['provider'], 'entity' => $source['link']->control_entity,
            'instance_key' => $source['link']->instance_key, 'source_revision' => self::sourceRevision($source['link']), 'sensor_id' => $sensor->id, 'mode' => 'manual']];
    }
}
