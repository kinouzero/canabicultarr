<?php

namespace App\Services;

use App\Exceptions\MeasurementException;
use App\Models\HomeAssistantDevice;
use App\Models\HomeAssistantLink;

class HomeAssistantClient extends RemoteSensorClient
{
    protected function configuration(): string
    {
        return 'home_assistant';
    }

    public function prefix(): string
    {
        return 'ha';
    }

    public function linkModel(): string
    {
        return HomeAssistantLink::class;
    }

    public function deviceModel(): string
    {
        return HomeAssistantDevice::class;
    }

    public function sourceColumn(): string
    {
        return 'home_assistant_link_id';
    }

    public static function validEntity(mixed $id): bool
    {
        return is_string($id) && strlen($id) <= 255 && preg_match('/^(sensor|number|input_number|binary_sensor|switch|input_boolean|light|fan|humidifier|valve)\.[a-z0-9_]+$/D', $id) === 1;
    }

    public function check(): void
    {
        $this->request('');
    }

    public function states(): array
    {
        $states = $this->request('states', limit: 4194304);
        if (! is_array($states) || ! array_is_list($states)) {
            throw new MeasurementException('measurements.errors.invalidResponse');
        }

        return array_map(fn ($state) => is_array($state) ? $this->normalizeState($state) : $state, $states);
    }

    public function normalizeState(array $state): array
    {
        $entity = $state['entity_id'] ?? null;
        $domain = is_string($entity) ? explode('.', $entity, 2)[0] : null;
        $state['attributes'] = is_array($state['attributes'] ?? null) ? $state['attributes'] : [];
        unset($state['attributes']['control_entity']);
        if (DeviceControlClient::validTarget('home_assistant', $entity)) {
            $state['attributes']['control_entity'] = $entity;
        }
        if (in_array($domain, ['binary_sensor', 'switch', 'input_boolean', 'light', 'fan', 'humidifier', 'valve'], true)) {
            if (in_array($state['state'] ?? null, ['on', 'off'], true)) {
                $state['state'] = $state['state'] === 'on' ? '1' : '0';
            }
            $state['attributes'] = is_array($state['attributes'] ?? null) ? $state['attributes'] : [];
            $state['attributes']['device_class'] = 'binary';
            $state['attributes']['unit_of_measurement'] = null;
        }

        return $state;
    }

    public function state(string $entity): array
    {
        if (! self::validEntity($entity)) {
            throw new MeasurementException('measurements.errors.invalidResponse');
        }
        $state = $this->request('states/'.rawurlencode($entity));
        if (! is_array($state) || ($state['entity_id'] ?? null) !== $entity) {
            throw new MeasurementException('measurements.errors.invalidResponse');
        }

        return $this->normalizeState($state);
    }

    public function devices(array $entities): array
    {
        foreach ($entities as $entity) {
            if (! self::validEntity($entity) && ! PumpControl::validTarget('home_assistant', $entity)) {
                throw new MeasurementException('measurements.errors.invalidResponse');
            }
        }
        // A fixed read-only template queries registry metadata; no actions or state writes.
        $template = '{% set ns = namespace(items=[]) %}{% for e in '.json_encode(array_values($entities), JSON_THROW_ON_ERROR).' %}{% set d = device_id(e) %}{% set ns.items = ns.items + [dict(entity_id=e, device_id=d, name=(device_attr(d, "name_by_user") or device_attr(d, "name")) if d else none, manufacturer=device_attr(d, "manufacturer") if d else none, model=device_attr(d, "model") if d else none)] %}{% endfor %}{{ ns.items | to_json }}';
        $rows = $this->request('template', ['template' => $template]);
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== count($entities)) {
            throw new MeasurementException('measurements.errors.invalidResponse');
        }
        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! array_key_exists('device_id', $row) || ! in_array($row['entity_id'] ?? null, $entities, true) || isset($result[$row['entity_id']])) {
                throw new MeasurementException('measurements.errors.invalidResponse');
            }
            if (($row['device_id'] ?? null) !== null && (! is_string($row['device_id']) || ! preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $row['device_id']))) {
                throw new MeasurementException('measurements.errors.invalidResponse');
            }
            $row['category'] = match (explode('.', $row['entity_id'])[0]) {
                'switch', 'input_boolean' => 'socket', 'light' => 'light', 'fan' => 'fan', 'humidifier' => 'humidifier', 'valve' => 'pump', default => 'sensor',
            };
            $result[$row['entity_id']] = $row;
        }

        return $result;
    }
}
