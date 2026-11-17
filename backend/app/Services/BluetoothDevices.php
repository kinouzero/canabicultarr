<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Exceptions\MeasurementException;
use App\Models\Asset;
use App\Models\BluetoothDevice;
use App\Models\BluetoothLink;
use App\Models\Measurement;
use App\Models\MeasurementType;
use App\Models\Sensor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class BluetoothDevices
{
    public const CHANNELS = [
        'temperature' => ['name' => 'Bluetooth temperature', 'unit' => '°C', 'decimals' => 2, 'min' => -273.15, 'max' => 327.67],
        'air_humidity' => ['name' => 'Bluetooth air humidity', 'unit' => '%', 'decimals' => 2, 'min' => 0, 'max' => 100],
        'battery' => ['name' => 'Bluetooth battery', 'unit' => '%', 'decimals' => 0, 'min' => 0, 'max' => 100],
    ];

    public function authorize(User $actor): User
    {
        $current = User::whereKey($actor->id)->lockForUpdate()->first();
        if (! $current || ! $current->is_active || $current->session_version !== $actor->session_version) {
            throw new AuthenticationException;
        }
        Gate::forUser($current)->authorize('integrations.manage');
        app(LocationAccess::class)->assertGlobal($current);

        return $current;
    }

    public function listing(User $actor, array $criteria)
    {
        return DB::transaction(function () use ($actor, $criteria) {
            $this->authorize($actor);
            $query = BluetoothDevice::with(['asset', 'links.sensor.measurementType'])->where('is_enabled', true);
            if (filled($criteria['search'] ?? null) || isset($criteria['state'])) {
                $query->whereHas('links', fn ($links) => IntegrationChannelFilters::apply($links, $criteria, 'entity_id'));
            }

            return $query->orderBy('id')->paginate(CollectionPagination::size($criteria))->through(fn ($device) => $this->payload($device));
        });
    }

    public function register(User $actor, array $data): array
    {
        $key = hash_hmac('sha256', strtoupper($data['address']), config('app.key'));

        return Cache::lock('bluetooth:'.$key, 10)->block(3, fn () => DB::transaction(function () use ($actor, $data, $key) {
            $this->authorize($actor);
            $device = BluetoothDevice::where('device_key', $key)->lockForUpdate()->first();
            if (! $device) {
                $asset = Asset::create(['name' => trim($data['name']), 'category' => 'sensor']);
                $device = BluetoothDevice::create(['asset_id' => $asset->id, 'device_key' => $key, 'address' => strtoupper($data['address'])]);
            } else {
                $asset = Asset::whereKey($device->asset_id)->lockForUpdate()->firstOrFail();
                if ($asset->status === AssetStatus::Retired) {
                    throw new MeasurementException('bluetooth.errors.disabled', 409);
                }
                if (! $device->is_enabled) {
                    $device->update(['is_enabled' => true, 'version' => $device->version + 1]);
                }
            }
            foreach ($data['kinds'] as $kind) {
                if (BluetoothLink::where('bluetooth_device_id', $device->id)->where('entity_id', $kind)->exists()) {
                    continue;
                }
                $definition = self::CHANNELS[$kind];
                $type = MeasurementType::firstOrCreate(['code' => 'bluetooth_'.$kind], ['name' => $definition['name'], 'nature' => $kind, 'unit' => $definition['unit'], 'decimal_places' => $definition['decimals']]);
                if ($type->nature !== $kind || $type->unit !== $definition['unit']) {
                    throw new MeasurementException('bluetooth.errors.typeConflict', 409);
                }
                $sensor = new Sensor(['name' => $definition['name'], 'channel_key' => 'bluetooth_'.$kind, 'is_enabled' => true, 'expected_interval_seconds' => 3600]);
                $sensor->device()->associate($asset);
                $sensor->measurementType()->associate($type);
                $sensor->save();
                BluetoothLink::create(['bluetooth_device_id' => $device->id, 'sensor_id' => $sensor->id, 'entity_id' => $kind, 'instance_key' => $key]);
            }
            Log::notice('bluetooth.paired', ['actor_uuid' => $actor->uuid, 'device_uuid' => $device->id]);

            return $this->payload($device->load(['asset', 'links.sensor.measurementType']));
        }));
    }

    public function payload(BluetoothDevice $device): array
    {
        return ['id' => $device->id, 'assetId' => $device->asset_id, 'name' => $device->asset->name, 'version' => $device->version, 'isEnabled' => $device->is_enabled,
            'channels' => $device->links->map(fn ($link) => ['id' => $link->id, 'sensorId' => $link->sensor_id, 'kind' => $link->entity_id, 'name' => $link->sensor->name, 'version' => $link->version,
                'isEnabled' => $link->is_enabled && $link->sensor->is_enabled, 'lastSeenAt' => $link->last_success_at?->toISOString()])->values()->all()];
    }

    public function remove(User $actor, BluetoothDevice $target, int $version): void
    {
        DB::transaction(function () use ($actor, $target, $version) {
            $this->authorize($actor);
            $device = BluetoothDevice::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $this->version($device, $version);
            $device->update(['is_enabled' => false, 'version' => $device->version + 1]);
            Log::notice('bluetooth.unpaired', ['actor_uuid' => $actor->uuid, 'device_uuid' => $device->id]);
        });
    }

    public function toggle(User $actor, BluetoothLink $target, array $data): void
    {
        DB::transaction(function () use ($actor, $target, $data) {
            $this->authorize($actor);
            BluetoothDevice::whereKey($target->bluetooth_device_id)->lockForUpdate()->firstOrFail();
            $link = BluetoothLink::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $this->version($link, $data['version']);
            $link->update(['is_enabled' => $data['is_enabled'], 'version' => $link->version + 1]);
        });
    }

    public function ingest(User $actor, BluetoothDevice $target, array $data): array
    {
        return DB::transaction(function () use ($actor, $target, $data) {
            $this->authorize($actor);
            $device = BluetoothDevice::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $this->version($device, $data['version']);
            $asset = Asset::whereKey($device->asset_id)->lockForUpdate()->firstOrFail();
            if (! $device->is_enabled || $asset->status === AssetStatus::Retired) {
                throw new MeasurementException('bluetooth.errors.disabled', 409);
            }
            $observed = CarbonImmutable::parse($data['observed_at'])->utc();
            if ($observed->lessThan(now()->subMinutes(15)) || $observed->greaterThan(now()->addMinutes(5))) {
                throw new MeasurementException('bluetooth.errors.invalidTime', 422);
            }
            $time = $observed->format('Y-m-d H:i:s.u');
            $counts = ['stored' => 0, 'duplicates' => 0];
            foreach ($data['readings'] as $reading) {
                $link = BluetoothLink::where('bluetooth_device_id', $device->id)->where('entity_id', $reading['kind'])->lockForUpdate()->first();
                if (! $link) {
                    throw new MeasurementException('bluetooth.errors.invalidInput', 422);
                }
                $this->version($link, $reading['version']);
                $sensor = Sensor::with('measurementType')->whereKey($link->sensor_id)->lockForUpdate()->firstOrFail();
                if (! $link->is_enabled || ! $sensor->is_enabled) {
                    throw new MeasurementException('bluetooth.errors.disabled', 409);
                }
                $definition = self::CHANNELS[$reading['kind']];
                if ($sensor->measurementType->nature !== $reading['kind'] || $sensor->measurementType->unit !== $definition['unit']) {
                    throw new MeasurementException('bluetooth.errors.typeConflict', 409);
                }
                $value = MeasurementValue::normalize((string) $reading['value']);
                if ((float) $value < $definition['min'] || (float) $value > $definition['max'] || ($reading['kind'] === 'battery' && floor((float) $value) !== (float) $value)) {
                    throw new MeasurementException('bluetooth.errors.invalidInput', 422);
                }
                $existing = Measurement::where('sensor_id', $sensor->id)->where('observed_at', $time)->first();
                if ($existing && ($existing->value !== $value || $existing->bluetooth_link_id !== $link->id || $existing->unit !== $definition['unit'])) {
                    throw new MeasurementException('bluetooth.errors.sampleConflict', 409);
                }
                if (! $existing) {
                    Measurement::create(['sensor_id' => $sensor->id, 'bluetooth_link_id' => $link->id, 'value' => $value, 'unit' => $definition['unit'], 'quality' => 'unknown', 'observed_at' => $time, 'received_at' => now(), ...app(MeasurementProvenance::class)->attributes($asset, $sensor, $time, $link, 'bluetooth')]);
                }
                $counts[$existing ? 'duplicates' : 'stored']++;
                $link->update(['last_attempt_at' => now(), 'last_success_at' => now(), 'last_error' => null]);
            }
            Log::info('bluetooth.readings_received', ['actor_uuid' => $actor->uuid, 'device_uuid' => $device->id, 'counts' => $counts]);

            return $counts;
        });
    }

    private function version($record, int $version): void
    {
        if ($record->version !== $version) {
            throw new MeasurementException('bluetooth.errors.conflict', 409);
        }
    }
}
