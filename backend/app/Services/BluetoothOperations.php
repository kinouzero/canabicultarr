<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Exceptions\MeasurementException;
use App\Jobs\RunBluetoothOperation;
use App\Models\BluetoothDevice;
use App\Models\User;
use App\Rules\ZonedTimestamp;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class BluetoothOperations
{
    public function __construct(private BluetoothDevices $devices, private BluetoothRadio $radio) {}

    private function authorize(User $actor): void
    {
        DB::transaction(fn () => $this->devices->authorize($actor));
    }

    public function status(User $actor): array
    {
        $this->authorize($actor);

        return [...$this->radio->status(), 'operationId' => Cache::get('bluetooth:active:'.$actor->id)];
    }

    public function start(User $actor, string $kind, array $input): array
    {
        $this->authorize($actor);
        if (! config('bluetooth.enabled')) {
            throw new MeasurementException('bluetooth.errors.unavailable');
        }
        $key = 'bluetooth:request:'.$actor->id.':'.$actor->session_version.':'.$input['request_id'];

        return Cache::lock('bluetooth:start:'.$actor->id, 10)->block(1, function () use ($actor, $kind, $input, $key) {
            if ($existing = Cache::get($key)) {
                $prior = $this->owned($actor, $existing);
                if ($prior['kind'] !== $kind || $prior['input'] !== $input) {
                    throw new MeasurementException('bluetooth.errors.conflict', 409);
                }

                return $this->get($actor, $existing);
            }
            $active = Cache::get('bluetooth:active:'.$actor->id);
            if ($active && (Cache::get('bluetooth:operation:'.$active)['status'] ?? '') === 'pending') {
                throw new MeasurementException('bluetooth.errors.busy', 429);
            }
            $id = (string) Str::uuid();
            Cache::put('bluetooth:operation:'.$id, ['id' => $id, 'kind' => $kind, 'owner' => $actor->id, 'session' => $actor->session_version,
                'created' => now()->timestamp, 'input' => $input, 'status' => 'pending', 'data' => null, 'error' => null], 300);
            Cache::put($key, $id, 300);
            Cache::put('bluetooth:active:'.$actor->id, $id, 300);
            RunBluetoothOperation::dispatch($id);

            return $this->get($actor, $id);
        });
    }

    public function get(User $actor, string $id): array
    {
        $this->authorize($actor);
        $record = $this->owned($actor, $id);
        if ($record['status'] === 'pending' && now()->timestamp - $record['created'] > 120) {
            $this->fail($id, 'bluetooth.errors.timeout');
            $record = $this->owned($actor, $id);
        }

        return array_intersect_key($record, array_flip(['id', 'kind', 'status', 'data', 'error']));
    }

    private function owned(User $actor, string $id): array
    {
        $record = Cache::get('bluetooth:operation:'.$id);
        abort_unless($record && $record['owner'] === $actor->id && $record['session'] === $actor->session_version, 404);

        return $record;
    }

    public function execute(string $id): void
    {
        Cache::lock('bluetooth:work:'.$id, 40)->get(function () use ($id) {
            $record = Cache::get('bluetooth:operation:'.$id);
            if (! $record || $record['status'] !== 'pending') {
                return;
            }
            try {
                if (now()->timestamp - $record['created'] > 120) {
                    throw new MeasurementException('bluetooth.errors.timeout');
                }
                $actor = User::find($record['owner']);
                if (! $actor || $actor->session_version !== $record['session']) {
                    throw new AuthenticationException;
                }
                $this->authorize($actor);
                $input = $record['input'];
                $private = [];
                if ($record['kind'] === 'discover') {
                    $result = [];
                    foreach (array_slice($this->radio->perform('discover'), 0, 100) as $candidate) {
                        if (! is_array($candidate) || ! preg_match('/^(?:(?:[0-9A-F]{2}:){5}[0-9A-F]{2}|[0-9A-F]{8}(?:-[0-9A-F]{4}){3}-[0-9A-F]{12})$/iD', $candidate['address'] ?? '')) {
                            continue;
                        }
                        $candidateId = (string) Str::uuid();
                        $private[$candidateId] = ['address' => strtoupper($candidate['address']), 'name' => mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', (string) ($candidate['name'] ?? 'Bluetooth sensor')), 0, 120) ?: 'Bluetooth sensor'];
                        $result[] = ['id' => $candidateId, 'name' => $private[$candidateId]['name'], 'rssi' => is_int($candidate['rssi'] ?? null) ? $candidate['rssi'] : null];
                    }
                } elseif ($record['kind'] === 'pair') {
                    $discovery = Cache::get('bluetooth:operation:'.$input['discovery_id']);
                    $candidate = $discovery['candidates'][$input['candidate_id']] ?? null;
                    if (! $discovery || $discovery['owner'] !== $actor->id || $discovery['session'] !== $actor->session_version || $discovery['kind'] !== 'discover' || $discovery['status'] !== 'completed' || ! $candidate) {
                        throw new MeasurementException('bluetooth.errors.expired', 422);
                    }
                    $sample = $this->sample($this->radio->perform('read', $candidate['address']));
                    $result = DB::transaction(function () use ($actor, $candidate, $sample) {
                        $device = $this->devices->register($actor, [...$candidate, 'kinds' => array_column($sample['readings'], 'kind')]);
                        if (collect($device['channels'])->contains('isEnabled', true)) {
                            $this->save($actor, BluetoothDevice::findOrFail($device['id']), $sample);
                        }

                        return $device;
                    });
                } else {
                    $device = BluetoothDevice::with(['asset', 'links.sensor.measurementType'])->findOrFail($input['device_id']);
                    if (! $device->address || ! $device->is_enabled || $device->asset->status === AssetStatus::Retired || $device->version !== $input['version'] || ! $device->links->contains(fn ($link) => $link->is_enabled && $link->sensor->is_enabled)) {
                        throw new MeasurementException('bluetooth.errors.disabled', 409);
                    }
                    $sample = $this->sample($this->radio->perform('read', $device->address));
                    $result = $this->save($actor, $device, $sample);
                }
                $this->authorize($actor);
                Cache::put('bluetooth:operation:'.$id, [...$record, 'status' => 'completed', 'data' => $result, 'candidates' => $private], 300);
            } catch (AuthenticationException|AuthorizationException) {
                $this->fail($id, 'bluetooth.errors.accessLost');
            } catch (MeasurementException $error) {
                $this->fail($id, $error->errorCode);
            } catch (Throwable) {
                $this->fail($id, 'bluetooth.errors.unavailable');
            }
        });
    }

    private function sample(array $sample): array
    {
        $validator = Validator::make($sample, ['observed_at' => ['required', new ZonedTimestamp], 'readings' => ['required', 'array', 'min:1', 'max:3'],
            'readings.*.kind' => ['required', Rule::in(array_keys(BluetoothDevices::CHANNELS)), 'distinct'], 'readings.*.value' => ['required', 'numeric']]);
        if ($validator->fails()) {
            throw new MeasurementException('bluetooth.errors.invalidReading', 422);
        }

        return $validator->validated();
    }

    private function save(User $actor, BluetoothDevice $device, array $sample): array
    {
        $device->loadMissing(['links.sensor.measurementType']);
        $readings = [];
        foreach ($sample['readings'] as $reading) {
            $link = $device->links->firstWhere('entity_id', $reading['kind']);
            if ($link && $link->is_enabled && $link->sensor->is_enabled) {
                $readings[] = [...$reading, 'version' => $link->version];
            }
        }
        if (! $readings) {
            throw new MeasurementException('bluetooth.errors.disabled', 409);
        }

        return $this->devices->ingest($actor, $device, ['version' => $device->version, 'observed_at' => $sample['observed_at'], 'readings' => $readings]);
    }

    public function fail(string $id, string $code): void
    {
        $record = Cache::get('bluetooth:operation:'.$id);
        if ($record && $record['status'] === 'pending') {
            Cache::put('bluetooth:operation:'.$id, [...$record, 'status' => 'failed', 'error' => $code], 300);
        }
    }
}
