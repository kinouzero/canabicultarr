<?php

namespace App\Services;

use App\Exceptions\AssetException;
use App\Exceptions\MeasurementException;
use App\Models\Asset;
use App\Models\HomeAssistantDevice;
use App\Models\HomebridgeDevice;
use Illuminate\Support\Sleep;

class DeviceControlClient
{
    public static function validTarget(?string $provider, mixed $entity): bool
    {
        return is_string($entity) && match ($provider) {
            'home_assistant' => preg_match('/^(light|switch|input_boolean|fan|humidifier)\.[a-z0-9_]+$/D', $entity) === 1,
            'homebridge' => preg_match('/^[a-f0-9]{64}:(On|Active)$/D', $entity) === 1,
            default => false,
        };
    }

    public function targets(string $provider, bool $pump = false, ?Asset $asset = null): array
    {
        $client = $this->client($provider);
        $sourceKeys = $asset ? $this->sourceKeys($asset, $client) : null;
        if ($sourceKeys === []) {
            return [];
        }

        $rows = $provider === 'home_assistant' ? $client->states() : $client->request('accessories', limit: 4194304);
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new MeasurementException('measurements.errors.invalidResponse');
        }
        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ($provider === 'home_assistant') {
                $entity = $row['entity_id'] ?? '';
                if (is_string($entity) && strlen($entity) <= 255 && ($pump ? PumpControl::validTarget($provider, $entity) : self::validTarget($provider, $entity))) {
                    $result[] = ['id' => $entity, 'name' => $row['attributes']['friendly_name'] ?? $entity, 'disabled' => in_array($row['state'] ?? '', ['unavailable', 'unknown'], true)];
                }
            } else {
                if ($sourceKeys !== null && ! in_array('device:'.HomebridgeClient::deviceId($row), $sourceKeys, true)) {
                    continue;
                }
                foreach ($row['serviceCharacteristics'] ?? [] as $characteristic) {
                    $entity = ($row['uniqueId'] ?? '').':'.($characteristic['type'] ?? '');
                    if (($pump ? PumpControl::validTarget($provider, $entity) : self::validTarget($provider, $entity)) && ($characteristic['canWrite'] ?? false) === true) {
                        $result[] = ['id' => $entity, 'name' => ($row['serviceName'] ?? $row['uniqueId']).' · '.$characteristic['type'], 'disabled' => ($characteristic['status'] ?? 0) !== 0];
                    }
                }
            }
        }
        if ($sourceKeys !== null && $client instanceof HomeAssistantClient) {
            $result = $this->filterHomeAssistantTargets($client, $result, $sourceKeys);
        }

        usort($result, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return $result;
    }

    public function matchesConnection(Asset $asset, string $provider): bool
    {
        return $this->sourceKeys($asset, $this->client($provider)) !== [];
    }

    private function sourceKeys(Asset $asset, RemoteSensorClient $client): ?array
    {
        $model = $client->deviceModel();
        $other = $model === HomeAssistantDevice::class ? HomebridgeDevice::class : HomeAssistantDevice::class;
        $devices = $model::where('asset_id', $asset->id)->get(['instance_key', 'source_key']);
        if ($devices->isEmpty() && ! $other::where('asset_id', $asset->id)->exists()) {
            return null;
        }

        return $devices->where('instance_key', $client->instanceKey())->pluck('source_key')->all();
    }

    private function filterHomeAssistantTargets(HomeAssistantClient $client, array $targets, array $sourceKeys): array
    {
        $devices = [];
        if (array_filter($sourceKeys, fn ($key) => str_starts_with($key, 'device:'))) {
            foreach (array_chunk(array_column($targets, 'id'), 200) as $entities) {
                $devices += $client->devices($entities);
            }
        }

        return array_values(array_filter($targets, function ($target) use ($devices, $sourceKeys) {
            $entity = $target['id'];
            $device = $devices[$entity]['device_id'] ?? null;

            return in_array('entity:'.$entity, $sourceKeys, true)
                || ($device !== null && in_array('device:'.$device, $sourceKeys, true));
        }));
    }

    private function client(string $provider): HomeAssistantClient|HomebridgeClient
    {
        return match ($provider) {
            'home_assistant' => app(HomeAssistantClient::class), 'homebridge' => app(HomebridgeClient::class), default => throw new AssetException('assets.pump.invalid', 422)
        };
    }

    public function command(array $control, bool $on): void
    {
        $client = $this->client($control['provider']);
        if (($control['instance_key'] ?? null) !== $client->instanceKey()) {
            throw new AssetException('assets.pump.unavailable', 422);
        }
        if ($control['provider'] === 'home_assistant') {
            [$domain] = explode('.', $control['entity']);
            $state = $client->request('states/'.rawurlencode($control['entity']));
            if (! is_array($state) || ($state['entity_id'] ?? null) !== $control['entity'] || in_array($state['state'] ?? 'unavailable', ['unavailable', 'unknown'], true)) {
                throw new AssetException('assets.pump.unavailable', 422);
            }
            if (in_array($domain, ['button', 'input_button'], true)) {
                if (! $on) {
                    throw new AssetException('assets.pump.notStoppable', 422);
                }
                $client->request('services/'.$domain.'/press', ['entity_id' => $control['entity']]);

                return;
            }
        } else {
            [$id, $type] = explode(':', $control['entity']);
            $service = $client->request('accessories/'.$id);
            $characteristic = collect($service['serviceCharacteristics'] ?? [])->firstWhere('type', $type);
            if (($service['uniqueId'] ?? null) !== $id || ($characteristic['canWrite'] ?? false) !== true || ($characteristic['status'] ?? 0) !== 0) {
                throw new AssetException('assets.pump.unavailable', 422);
            }
        }
        $this->writeState($client, $control['entity'], $on);
    }

    public function setState(array $control, bool $on): void
    {
        $client = $this->client($control['provider']);
        $current = $this->state($control);
        if ($current === $on) {
            return;
        }
        if ($current === null) {
            throw new AssetException('assets.power.unavailable', 422);
        }

        $this->writeState($client, $control['entity'], $on);

        // Bridges can report the old value briefly. Repeat reads only, never the physical write.
        foreach ([0, 250, 500, 1000] as $delayMs) {
            if ($delayMs > 0) {
                Sleep::usleep($delayMs * 1000);
            }
            if ($this->state($control) === $on) {
                return;
            }
        }

        throw new AssetException('assets.power.uncertain', 502);
    }

    private function writeState(HomeAssistantClient|HomebridgeClient $client, string $entity, bool $on): void
    {
        if ($client instanceof HomeAssistantClient) {
            [$domain] = explode('.', $entity);
            $client->request('services/'.$domain.'/'.($on ? 'turn_on' : 'turn_off'), ['entity_id' => $entity]);

            return;
        }

        [$id, $type] = explode(':', $entity);
        $client->request('accessories/'.$id, ['characteristicType' => $type, 'value' => $type === 'On' ? $on : (int) $on], method: 'PUT');
    }

    public function state(array $control): ?bool
    {
        $client = $this->client($control['provider']);
        if (($control['instance_key'] ?? null) !== $client->instanceKey()) {
            throw new AssetException('assets.power.unavailable', 422);
        }
        if ($control['provider'] === 'home_assistant') {
            $row = $client->request('states/'.rawurlencode($control['entity']));
            if (! is_array($row) || ($row['entity_id'] ?? null) !== $control['entity']) {
                throw new AssetException('assets.power.unavailable', 422);
            }

            return match ($row['state'] ?? null) {
                'on' => true, 'off' => false, default => null
            };
        }
        [$id, $type] = explode(':', $control['entity']);
        $row = $client->request('accessories/'.$id);
        $characteristic = collect($row['serviceCharacteristics'] ?? [])->firstWhere('type', $type);
        if (($row['uniqueId'] ?? null) !== $id || ($characteristic['status'] ?? 0) !== 0 || ($characteristic['canWrite'] ?? false) !== true) {
            return null;
        }

        return match ($characteristic['value'] ?? null) {
            true, 1 => true, false, 0 => false, default => null
        };
    }
}
