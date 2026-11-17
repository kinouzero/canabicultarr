<?php

namespace App\Services;

use App\Exceptions\MeasurementException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;

class BluetoothRadio
{
    private function relay(): bool
    {
        return config('bluetooth.transport') === 'relay'
            || (config('bluetooth.transport') === 'auto' && filled(config('bluetooth.relay_url')));
    }

    public function status(): array
    {
        return ['enabled' => (bool) config('bluetooth.enabled'), 'adapter' => config('bluetooth.adapter'), 'transport' => $this->relay() ? 'relay' : 'bluez'];
    }

    public function perform(string $action, ?string $address = null): array
    {
        if (! in_array(config('bluetooth.transport'), ['auto', 'bluez', 'relay'], true)) {
            throw new MeasurementException('bluetooth.errors.relayConfiguration');
        }
        if (! config('bluetooth.enabled')) {
            throw new MeasurementException('bluetooth.errors.unavailable');
        }
        try {
            return Cache::lock('bluetooth:radio:'.config('bluetooth.adapter'), 35)->block(1, function () use ($action, $address) {
                if ($this->relay()) {
                    $payload = app(BluetoothRelay::class)->request($action, $address);
                    $successful = true;
                } else {
                    $result = Process::timeout(25)->env(['DBUS_SYSTEM_BUS_ADDRESS' => 'unix:path=/run/dbus/system_bus_socket'])->input(json_encode(['action' => $action, 'adapter' => config('bluetooth.adapter'), 'address' => $address], JSON_THROW_ON_ERROR))
                        ->run([config('bluetooth.python'), base_path('scripts/bluetooth/bridge.py')]);
                    $payload = json_decode($result->output(), true, 32, JSON_THROW_ON_ERROR);
                    $successful = $result->successful();
                }
                if (! $successful || ! is_array($payload) || ($payload['ok'] ?? false) !== true || ! is_array($payload['data'] ?? null)) {
                    $code = in_array($payload['error'] ?? '', ['timeout', 'invalidReading', 'unsupportedDevice', 'disconnected', 'invalidInput'], true) ? $payload['error'] : 'unavailable';
                    throw new MeasurementException('bluetooth.errors.'.$code);
                }

                return $payload['data'];
            });
        } catch (MeasurementException $error) {
            throw $error;
        } catch (LockTimeoutException) {
            throw new MeasurementException('bluetooth.errors.busy', 429);
        } catch (Throwable) {
            throw new MeasurementException('bluetooth.errors.unavailable');
        }
    }
}
