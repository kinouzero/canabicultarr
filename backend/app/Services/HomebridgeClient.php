<?php

namespace App\Services;

use App\Exceptions\MeasurementException;
use App\Models\HomebridgeDevice;
use App\Models\HomebridgeLink;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class HomebridgeClient extends RemoteSensorClient
{
    private array $prefetched = [];

    private bool $hasPrefetch = false;

    public function prefetch(array $entities): void
    {
        $this->prefetched = collect($this->statesFor($entities))->keyBy('entity_id')->all();
        $this->hasPrefetch = true;
    }

    public function __construct(private ?CarbonImmutable $observedAt = null) {}

    protected function configuration(): string
    {
        return 'homebridge';
    }

    public function configured(): bool
    {
        return trim((string) config('homebridge.url')) !== ''
            && trim((string) config('homebridge.username')) !== ''
            && (string) config('homebridge.password') !== '';
    }

    public function request(string $path, ?array $body = null, int $limit = 262144, string $method = 'POST'): mixed
    {
        if (! $this->configured()) {
            throw new MeasurementException('measurements.errors.notConfigured');
        }
        $token = $this->sessionToken();
        try {
            return $this->sendRequest($path, $body, $limit, $token, $method);
        } catch (MeasurementException $exception) {
            if ($exception->providerStatus !== 401) {
                throw $exception;
            }

            // Retry one rejected session, reusing a concurrent worker's renewal.
            return $this->sendRequest($path, $body, $limit, $this->sessionToken($token), $method);
        }
    }

    public function eventToken(?string $rejected = null): string
    {
        return $this->sessionToken($rejected);
    }

    private function sessionToken(?string $rejected = null): string
    {
        $key = 'homebridge:session:'.hash_hmac('sha256', json_encode([
            rtrim(config('homebridge.url'), '/'), config('homebridge.username'), config('homebridge.password'),
        ]), config('app.key'));
        try {
            return Cache::lock($key.':lock', 10)->block(3, function () use ($key, $rejected) {
                $cached = Cache::get($key);
                if (is_array($cached) && isset($cached['error'])) {
                    throw new MeasurementException($cached['error']);
                }
                if (is_array($cached) && isset($cached['token'])) {
                    try {
                        $token = Crypt::decryptString($cached['token']);
                        if ($token !== $rejected) {
                            return $token;
                        }
                    } catch (DecryptException) {
                        Cache::forget($key);
                    }
                }
                try {
                    $login = $this->sendRequest('auth/login', [
                        'username' => config('homebridge.username'), 'password' => config('homebridge.password'),
                    ], 65536, null);
                    if (! is_array($login)) {
                        throw new MeasurementException('measurements.errors.invalidResponse');
                    }
                    $token = $login['access_token'] ?? null;
                    $seconds = filter_var($login['expires_in'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if (! is_string($token) || $token === '' || strlen($token) > 16384 || preg_match('/[\x00-\x20\x7f]/', $token) || $seconds === false) {
                        throw new MeasurementException('measurements.errors.invalidResponse');
                    }
                    $seconds = min(86400, $seconds);
                    $ttl = max(1, $seconds - min(30, max(1, (int) ($seconds / 10))));
                    Cache::put($key, ['token' => Crypt::encryptString($token)], $ttl);

                    return $token;
                } catch (MeasurementException $exception) {
                    $code = $exception->providerStatus === 412 ? 'measurements.errors.twoFactorRequired' : $exception->errorCode;
                    // Avoid repeated failed logins from every sensor or concurrent caller.
                    Cache::put($key, ['error' => $code], 30);
                    throw new MeasurementException($code);
                }
            });
        } catch (LockTimeoutException) {
            throw new MeasurementException('measurements.errors.providerUnavailable');
        }
    }

    public function prefix(): string
    {
        return 'hb';
    }

    public function linkModel(): string
    {
        return HomebridgeLink::class;
    }

    public function deviceModel(): string
    {
        return HomebridgeDevice::class;
    }

    public function sourceColumn(): string
    {
        return 'homebridge_link_id';
    }

    public static function validEntity(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[a-f0-9]{64}:[1-9][0-9]{0,9}$/D', $id) === 1;
    }

    private function accessories(): array
    {
        $rows = $this->request('accessories', limit: 4194304);
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new MeasurementException('measurements.errors.invalidResponse');
        }

        return $rows;
    }

    public function check(): void
    {
        $this->accessories();
    }

    private function characteristic(array $service, array $characteristic): ?array
    {
        $id = ($service['uniqueId'] ?? '').':'.($characteristic['iid'] ?? '');
        $type = $characteristic['type'] ?? null;
        $format = $characteristic['format'] ?? null;
        if (! self::validEntity($id) || ($characteristic['canRead'] ?? false) !== true || ! is_string($type) || ! preg_match('/^[A-Za-z][A-Za-z0-9]{0,79}$/D', $type) || ! in_array($format, ['float', 'int', 'uint8', 'uint16', 'uint32', 'uint64', 'bool'], true)) {
            return null;
        }
        $unit = $characteristic['unit'] ?? null;
        if ($unit !== null && (! is_string($unit) || mb_strlen($unit) > 24 || preg_match('/[\x00-\x1f\x7f]/u', $unit))) {
            return null;
        }
        $unit = match ($unit) {
            'celsius' => '°C', 'percentage' => '%', 'lux' => 'lx', 'arcdegrees' => '°', 'seconds' => 's', default => $unit
        };
        $raw = $characteristic['value'] ?? null;
        $value = 'unavailable';
        // A failed refresh may retain an old numeric value: never ingest it.
        if (($characteristic['status'] ?? 0) === 0) {
            if ($format === 'bool' && in_array($raw, [true, false, 0, 1], true)) {
                $value = $raw ? '1' : '0';
            } elseif (is_int($raw) || is_float($raw) && is_finite($raw)) {
                $value = (string) $raw;
            }
        }
        $class = match ($type) {
            'CurrentTemperature' => 'temperature', 'CurrentRelativeHumidity' => 'humidity', 'CurrentAmbientLightLevel' => 'illuminance', 'BatteryLevel' => 'battery', default => $type
        };
        $name = is_string($service['serviceName'] ?? null) ? $service['serviceName'] : $service['uniqueId'];

        return ['entity_id' => $id, 'state' => $value, 'attributes' => ['friendly_name' => Str::limit($name.' · '.Str::headline($type), 120, ''), 'unit_of_measurement' => $unit, 'device_class' => $class, 'control_entity' => in_array($type, ['On', 'Active'], true) && ($characteristic['canWrite'] ?? false) === true && in_array($format, ['bool', 'int', 'uint8'], true) ? $service['uniqueId'].':'.$type : null], 'last_reported' => ($this->observedAt ?? now())->utc()->toISOString()];
    }

    public function normalize(array $service): array
    {
        if (! is_string($service['uniqueId'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $service['uniqueId']) || ! is_array($service['serviceCharacteristics'] ?? null) || ! array_is_list($service['serviceCharacteristics'])) {
            throw new MeasurementException('measurements.errors.invalidResponse');
        }
        $rows = [];
        foreach ($service['serviceCharacteristics'] as $characteristic) {
            if (is_array($characteristic) && ($row = $this->characteristic($service, $characteristic))) {
                if (isset($rows[$row['entity_id']])) {
                    throw new MeasurementException('measurements.errors.invalidResponse');
                }
                $rows[$row['entity_id']] = $row;
            }
        }

        return $rows;
    }

    public function states(): array
    {
        return $this->statesFrom($this->accessories());
    }

    public function inventory(): array
    {
        $services = $this->accessories();
        $states = $this->statesFrom($services);

        return ['states' => $states, 'devices' => $this->devicesFrom(array_column($states, 'entity_id'), $services)];
    }

    private function statesFrom(array $services): array
    {
        $result = [];
        foreach ($services as $service) {
            if (! is_array($service)) {
                throw new MeasurementException('measurements.errors.invalidResponse');
            }
            foreach ($this->normalize($service) as $id => $row) {
                if (isset($result[$id])) {
                    throw new MeasurementException('measurements.errors.invalidResponse');
                }
                $result[$id] = $row;
            }
        }

        return array_values($result);
    }

    public function statesFor(array $entities): array
    {
        $services = [];
        foreach ($entities as $entity) {
            if (! self::validEntity($entity)) {
                throw new MeasurementException('measurements.errors.invalidResponse');
            }
            [$id] = explode(':', $entity);
            if (! isset($services[$id])) {
                $service = $this->request('accessories/'.$id);
                if (! is_array($service) || ($service['uniqueId'] ?? null) !== $id) {
                    throw new MeasurementException('measurements.errors.invalidResponse');
                }
                $services[$id] = $service;
            }
        }

        return array_values(array_filter($this->statesFrom(array_values($services)), fn ($state) => in_array($state['entity_id'], $entities, true)));
    }

    public function state(string $entity): array
    {
        if ($this->hasPrefetch) {
            return $this->prefetched[$entity] ?? throw new MeasurementException('measurements.errors.entityMissing');
        }
        if (! self::validEntity($entity)) {
            throw new MeasurementException('measurements.errors.invalidResponse');
        }
        [$serviceId] = explode(':', $entity);
        $service = $this->request('accessories/'.$serviceId);
        if (! is_array($service) || ($service['uniqueId'] ?? null) !== $serviceId) {
            throw new MeasurementException('measurements.errors.invalidResponse');
        }

        return $this->normalize($service)[$entity] ?? throw new MeasurementException('measurements.errors.entityMissing');
    }

    public function devices(array $entities): array
    {
        return $this->devicesFrom($entities, $this->accessories());
    }

    public static function deviceId(array $service): ?string
    {
        $username = $service['instance']['username'] ?? null;
        $aid = $service['aid'] ?? null;
        if (! is_string($username) || $username === '' || strlen($username) > 128 || ! is_int($aid) || $aid < 1) {
            return null;
        }

        return hash('sha256', $username.':'.$aid);
    }

    private function devicesFrom(array $entities, array $services): array
    {
        $selected = array_fill_keys($entities, true);
        $result = [];
        foreach ($services as $service) {
            if (! is_array($service)) {
                throw new MeasurementException('measurements.errors.invalidResponse');
            }
            foreach ($this->normalize($service) as $id => $row) {
                if (! isset($selected[$id])) {
                    continue;
                }
                $deviceId = self::deviceId($service);
                if ($deviceId === null || isset($result[$id])) {
                    throw new MeasurementException('measurements.errors.invalidResponse');
                }
                $information = is_array($service['accessoryInformation'] ?? null) ? $service['accessoryInformation'] : [];
                $category = match ($service['type'] ?? '') {
                    'Valve' => 'pump', 'Outlet', 'Switch' => 'socket', 'Lightbulb' => 'light', 'Fan', 'Fanv2' => 'fan', 'HumidifierDehumidifier' => 'humidifier', default => 'sensor'
                };
                $result[$id] = ['entity_id' => $id, 'device_id' => $deviceId, 'name' => $information['Name'] ?? $service['serviceName'] ?? null, 'manufacturer' => $information['Manufacturer'] ?? null, 'model' => $information['Model'] ?? null, 'category' => $category];
            }
        }
        if (count($result) !== count($entities)) {
            throw new MeasurementException('measurements.errors.entityMissing', 422);
        }

        return $result;
    }
}
