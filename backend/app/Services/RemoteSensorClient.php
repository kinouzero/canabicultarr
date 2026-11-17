<?php

namespace App\Services;

use App\Exceptions\MeasurementException;
use Illuminate\Support\Facades\Http;
use Throwable;

abstract class RemoteSensorClient
{
    abstract protected function configuration(): string;

    abstract public function prefix(): string;

    abstract public function linkModel(): string;

    abstract public function deviceModel(): string;

    abstract public function sourceColumn(): string;

    abstract public static function validEntity(mixed $id): bool;

    abstract public function check(): void;

    abstract public function states(): array;

    abstract public function state(string $entity): array;

    public function statesFor(array $entities): array
    {
        return array_map(fn ($entity) => $this->state($entity), $entities);
    }

    abstract public function devices(array $entities): array;

    public function normalizeState(array $state): array
    {
        return $state;
    }

    public function controlEntity(array $state): ?string
    {
        $entity = $state['attributes']['control_entity'] ?? null;
        $provider = $this->prefix() === 'hb' ? 'homebridge' : 'home_assistant';

        return DeviceControlClient::validTarget($provider, $entity) ? $entity : null;
    }

    public function inventory(): array
    {
        $states = array_values(array_filter($this->states(), fn ($state) => is_array($state) && $this->validEntity($state['entity_id'] ?? null)));
        $devices = [];
        foreach (array_chunk(array_column($states, 'entity_id'), 200) as $entities) {
            $devices += $this->devices($entities);
        }

        return ['states' => $states, 'devices' => $devices];
    }

    public function configured(): bool
    {
        return trim((string) config($this->configuration().'.url')) !== '' && trim((string) config($this->configuration().'.token')) !== '';
    }

    public function instanceKey(): string
    {
        return hash('sha256', rtrim((string) config($this->configuration().'.url'), '/'));
    }

    public function request(string $path, ?array $body = null, int $limit = 262144, string $method = 'POST'): mixed
    {
        return $this->sendRequest($path, $body, $limit, config($this->configuration().'.token'), $method);
    }

    protected function sendRequest(string $path, ?array $body, int $limit, ?string $token, string $method = 'POST'): mixed
    {
        $url = rtrim((string) config($this->configuration().'.url'), '/');
        $parts = parse_url($url);
        if (! $this->configured() || ! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new MeasurementException('measurements.errors.notConfigured');
        }
        try {
            $request = Http::acceptJson()->connectTimeout(2)->timeout(5)->withoutRedirecting()->withOptions(['stream' => true]);
            if ($token !== null) {
                $request = $request->withToken($token);
            }
            $response = $body === null ? $request->get($url.'/api/'.$path) : $request->send($method, $url.'/api/'.$path, ['json' => $body]);
            try {
                if (in_array($response->status(), [401, 403], true)) {
                    throw new MeasurementException('measurements.errors.accessDenied', providerStatus: $response->status());
                }
                if ($response->status() === 404) {
                    throw new MeasurementException('measurements.errors.entityMissing');
                }
                if (! $response->successful()) {
                    throw new MeasurementException('measurements.errors.providerUnavailable', providerStatus: $response->status());
                }
                $stream = $response->toPsrResponse()->getBody();
                $text = '';
                while (! $stream->eof() && strlen($text) <= $limit) {
                    $text .= $stream->read(min(65536, $limit + 1 - strlen($text)));
                }
                if (strlen($text) > $limit) {
                    throw new MeasurementException('measurements.errors.responseTooLarge');
                }

                return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            } finally {
                $response->close();
            }
        } catch (MeasurementException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new MeasurementException('measurements.errors.providerUnavailable');
        }
    }
}
