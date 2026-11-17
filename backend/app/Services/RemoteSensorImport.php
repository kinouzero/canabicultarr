<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Enums\MeasurementNature;
use App\Exceptions\MeasurementException;
use App\Models\Asset;
use App\Models\HomeAssistantLink;
use App\Models\HomebridgeLink;
use App\Models\HttpMeasurementSource;
use App\Models\MeasurementType;
use App\Models\MqttMeasurementSource;
use App\Models\RemoteSensorLink;
use App\Models\Sensor;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RemoteSensorImport
{
    public function __construct(private RemoteSensorClient $client) {}

    public function eligible(array $state): bool
    {
        $unit = $state['attributes']['unit_of_measurement'] ?? null;

        return $this->client->validEntity($state['entity_id'] ?? null) && is_array($state['attributes'] ?? null) && ($unit === null || (is_string($unit) && mb_strlen($unit) <= 24 && ! preg_match('/[\x00-\x1f\x7f]/u', $unit)));
    }

    private function sourceKey(array $meta, string $entity): string
    {
        if (isset($meta['device_id'])) {
            return 'device:'.$meta['device_id'];
        }
        $key = 'entity:'.$entity;

        return strlen($key) <= 255 ? $key : 'entity:'.hash('sha256', $entity);
    }

    private function deviceInventory(): array
    {
        $inventory = $this->client->inventory();
        $mapped = array_fill_keys($this->client->linkModel()::where('instance_key', $this->client->instanceKey())->pluck('entity_id')->all(), true);
        $groups = [];
        foreach ($inventory['states'] as $state) {
            if (! is_array($state) || ! $this->eligible($state)) {
                continue;
            }
            try {
                MeasurementValue::normalize($state['state'] ?? null);
                $available = true;
            } catch (MeasurementException) {
                $available = false;
            }
            if (! $available && ! in_array($state['state'] ?? null, ['unknown', 'unavailable'], true)) {
                continue;
            }
            $entity = $state['entity_id'];
            $meta = $inventory['devices'][$entity] ?? throw new MeasurementException('measurements.errors.invalidResponse');
            $key = $this->sourceKey($meta, $entity);
            $name = is_string($state['attributes']['friendly_name'] ?? null) ? Str::limit($state['attributes']['friendly_name'], 120, '') : Str::limit($entity, 120, '');
            if (! isset($groups[$key])) {
                $groups[$key] = ['id' => $key, 'name' => is_string($meta['name'] ?? null) && trim($meta['name']) !== '' ? Str::limit(trim($meta['name']), 120, '') : $name,
                    'category' => $meta['category'] ?? 'sensor', 'manufacturer' => is_string($meta['manufacturer'] ?? null) ? Str::limit($meta['manufacturer'], 120, '') : null, 'model' => is_string($meta['model'] ?? null) ? Str::limit($meta['model'], 120, '') : null, 'available' => false, 'imported' => true, 'entityCount' => 0, 'importedCount' => 0, 'entities' => []];
            }
            $group = &$groups[$key];
            $group['available'] = $group['available'] || $available;
            $group['imported'] = $group['imported'] && isset($mapped[$entity]);
            $group['entityCount']++;
            $group['importedCount'] += isset($mapped[$entity]) ? 1 : 0;
            if (in_array($group['category'], ['sensor', 'other'], true) || ($meta['category'] ?? null) === 'pump') {
                $group['category'] = $meta['category'] ?? 'sensor';
            }
            $group['entities'][] = ['id' => $entity, 'name' => $name, 'unit' => $state['attributes']['unit_of_measurement'] ?? null];
            unset($group);
        }

        return ['groups' => $groups, 'states' => $inventory['states'], 'devices' => $inventory['devices']];
    }

    public function discover(array $criteria): LengthAwarePaginator
    {
        $search = Str::lower(trim($criteria['search'] ?? ''));
        $rows = [];
        foreach ($this->deviceInventory()['groups'] as $group) {
            $haystack = $group['name'].' '.$group['id'].' '.implode(' ', array_merge(array_column($group['entities'], 'name'), array_column($group['entities'], 'id')));
            if ($search !== '' && ! str_contains(Str::lower($haystack), $search)) {
                continue;
            }
            // A bounded preview is enough to review a device; imports expand on the server.
            $group['entities'] = array_slice($group['entities'], 0, 5);
            $rows[] = $group;
        }
        usort($rows, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']) ?: strcmp($a['id'], $b['id']));
        $page = (int) ($criteria['page'] ?? 1);

        return new LengthAwarePaginator(array_slice($rows, ($page - 1) * CollectionPagination::size($criteria), CollectionPagination::size($criteria)), count($rows), CollectionPagination::size($criteria), $page, ['path' => request()->url()]);
    }

    public function importDevices(User $actor, array $deviceIds): array
    {
        app(LocationAccess::class)->assertGlobal($actor);
        Gate::forUser($actor->fresh())->authorize('integrations.manage');
        $inventory = $this->deviceInventory();
        $entities = [];
        foreach ($deviceIds as $id) {
            $group = $inventory['groups'][$id] ?? throw new MeasurementException('measurements.errors.entityMissing', 422);
            $entities = array_merge($entities, array_column($group['entities'], 'id'));
        }
        if (count($entities) > 1000) {
            throw new MeasurementException('measurements.validation.inputInvalid', 422);
        }

        return $this->import($actor, $entities, $inventory);
    }

    public function import(User $actor, array $entities, ?array $inventory = null): array
    {
        app(LocationAccess::class)->assertGlobal($actor);
        Gate::forUser($actor->fresh())->authorize('integrations.manage');
        $states = [];
        foreach ($inventory['states'] ?? $this->client->states() as $state) {
            if (is_array($state) && in_array($state['entity_id'] ?? null, $entities, true) && $this->eligible($state)) {
                $states[$state['entity_id']] = $state;
            }
        }
        if (count($states) !== count($entities)) {
            throw new MeasurementException('measurements.errors.entityMissing', 422);
        }
        $devices = $inventory['devices'] ?? $this->client->devices($entities);
        $instance = $this->client->instanceKey();
        $result = DB::transaction(function () use ($actor, $entities, $states, $devices, $instance, $inventory) {
            // Serialize imports globally so distinct administrators reuse the same registry devices/types.
            DB::table('application_setup')->where('id', 1)->lockForUpdate()->firstOrFail();
            $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($current)->authorize('integrations.manage');
            app(LocationAccess::class)->assertGlobal($current);
            $counts = ['devicesCreated' => 0, 'sensorsCreated' => 0, 'typesCreated' => 0, 'alreadyImported' => 0];
            foreach ($entities as $entity) {
                $previous = $this->client->linkModel()::withTrashed()->where('instance_key', $instance)->where('entity_id', $entity)->first();
                if ($previous && ! $previous->trashed()) {
                    $counts['alreadyImported']++;

                    continue;
                }
                if ($previous) {
                    $sensor = Sensor::whereKey($previous->sensor_id)->firstOrFail();
                    $asset = Asset::whereKey($sensor->asset_id)->lockForUpdate()->firstOrFail();
                    app(LocationAccess::class)->assertRecord($current, $asset);
                    $sensor = Sensor::whereKey($sensor->id)->lockForUpdate()->firstOrFail();
                    if ($asset->status === AssetStatus::Retired) {
                        throw new MeasurementException('measurements.errors.deviceRetired', 409);
                    }
                    foreach ([HomeAssistantLink::class, HomebridgeLink::class, HttpMeasurementSource::class, MqttMeasurementSource::class] as $source) {
                        if ($source::where('sensor_id', $sensor->id)->exists()) {
                            throw new MeasurementException('measurements.errors.sourceConflict', 409);
                        }
                    }
                    $previous = $this->client->linkModel()::withTrashed()->whereKey($previous->id)->lockForUpdate()->firstOrFail();
                    $previous->is_enabled = true;
                    $previous->version++;
                    $previous->restore();

                    continue;
                }
                $state = $states[$entity];
                $meta = $devices[$entity];
                if ($inventory !== null) {
                    $groupKey = $this->sourceKey($meta, $entity);
                    $meta['category'] = $inventory['groups'][$groupKey]['category'];
                }
                $name = is_string($state['attributes']['friendly_name'] ?? null) ? Str::limit($state['attributes']['friendly_name'], 120, '') : Str::limit($entity, 120, '');
                $sourceKey = $this->sourceKey($meta, $entity);
                $device = $this->client->deviceModel()::where('instance_key', $instance)->where('source_key', $sourceKey)->first();
                if (! $device) {
                    $text = fn ($value) => is_string($value) && trim($value) !== '' ? Str::limit(trim($value), 120, '') : null;
                    $asset = Asset::create(['name' => $text($meta['name'] ?? null) ?? $name, 'status' => AssetStatus::Active, 'manufacturer' => $text($meta['manufacturer'] ?? null), 'model' => $text($meta['model'] ?? null), 'category' => $meta['category'] ?? 'sensor']);
                    $device = $this->client->deviceModel()::create(['instance_key' => $instance, 'source_key' => $sourceKey, 'asset_id' => $asset->id]);
                    $counts['devicesCreated']++;
                }
                $asset = Asset::whereKey($device->asset_id)->lockForUpdate()->firstOrFail();
                if ($asset->status === AssetStatus::Retired) {
                    throw new MeasurementException('measurements.errors.deviceRetired', 409);
                }
                $unit = $state['attributes']['unit_of_measurement'] ?? null;
                $class = $state['attributes']['device_class'] ?? 'numeric';
                $code = $this->client->prefix().'_'.substr(hash('sha256', json_encode([$class, $unit])), 0, 32);
                $type = MeasurementType::where('code', $code)->lockForUpdate()->first();
                if ($type && $type->unit !== $unit) {
                    throw new MeasurementException('measurements.errors.typeConflict', 409);
                }
                if (! $type) {
                    $type = MeasurementType::create(['code' => $code, 'name' => Str::limit((is_string($class) ? $class : 'numeric').($unit ? ' ('.$unit.')' : ''), 120, ''), 'unit' => $unit, 'nature' => MeasurementNature::fromDeviceClass($class, $unit)->value, 'decimal_places' => MeasurementNature::fromDeviceClass($class, $unit) === MeasurementNature::Binary ? 0 : 3]);
                    $counts['typesCreated']++;
                }
                $sensor = new Sensor(['name' => $name, 'channel_key' => $this->client->prefix().'_'.substr(hash('sha256', $entity), 0, 32), 'is_enabled' => true, 'expected_interval_seconds' => 60]);
                $sensor->asset_id = $device->asset_id;
                $sensor->measurement_type_id = $type->id;
                $sensor->save();
                $this->client->linkModel()::create(['sensor_id' => $sensor->id, 'instance_key' => $instance, 'entity_id' => $entity, 'control_entity' => $this->client->controlEntity($state)]);
                $counts['sensorsCreated']++;
            }

            return $counts;
        });
        Log::notice($this->client->prefix().'.entities_imported', ['actor_uuid' => $actor->uuid, 'counts' => $result]);

        return $result;
    }

    public function remove(User $actor, RemoteSensorLink $target, int $version): void
    {
        DB::transaction(function () use ($actor, $target, $version) {
            $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($current)->authorize('integrations.manage');
            $sensor = Sensor::findOrFail($target->sensor_id);
            $asset = Asset::whereKey($sensor->asset_id)->lockForUpdate()->firstOrFail();
            app(LocationAccess::class)->assertRecord($current, $asset);
            Sensor::whereKey($sensor->id)->lockForUpdate()->firstOrFail();
            $link = $this->client->linkModel()::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($version !== $link->version) {
                throw new MeasurementException('measurements.errors.conflict', 409);
            }
            $link->is_enabled = false;
            $link->version++;
            $link->save();
            $link->delete();
            Log::notice($this->client->prefix().'.link_removed', ['actor_uuid' => $actor->uuid, 'link_uuid' => $link->id]);
        });
    }

    public function toggle(User $actor, RemoteSensorLink $target, array $data): RemoteSensorLink
    {
        return DB::transaction(function () use ($actor, $target, $data) {
            $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($current)->authorize('integrations.manage');
            if (! app(LocationAccess::class)->record($current, $target)) {
                throw new AuthorizationException;
            }
            $link = $this->client->linkModel()::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ((int) $data['version'] !== $link->version) {
                throw new MeasurementException('measurements.errors.conflict', 409);
            }
            if ($link->is_enabled !== (bool) $data['is_enabled']) {
                $link->is_enabled = $data['is_enabled'];
                $link->version++;
                $link->save();
                Log::notice($this->client->prefix().'.link_updated', ['actor_uuid' => $actor->uuid, 'link_uuid' => $link->id]);
            }

            return $link->load(SensorAdministration::relations('sensor.'));
        });
    }
}
