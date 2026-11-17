<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Exceptions\MeasurementException;
use App\Models\Asset;
use App\Models\HomeAssistantLink;
use App\Models\HomebridgeLink;
use App\Models\Sensor;
use App\Rules\ZonedTimestamp;
use Carbon\CarbonImmutable;

class AssetLiveReadings
{
    public function read(Asset $asset, array $ids): array
    {
        if ($asset->status !== AssetStatus::Active) {
            return [];
        }
        $sensors = $asset->sensors()->whereIn('id', $ids)->where('is_enabled', true)->with('measurementType')->get()->keyBy('id');
        $sources = [];
        foreach ([HomeAssistantClient::class => HomeAssistantLink::class, HomebridgeClient::class => HomebridgeLink::class] as $clientClass => $model) {
            $client = app($clientClass);
            if (! $client->configured()) {
                continue;
            }
            foreach ($model::whereIn('sensor_id', $sensors->keys())->where('is_enabled', true)->where('instance_key', $client->instanceKey())->get() as $link) {
                $sources[$link->sensor_id][] = ['client' => $clientClass, 'entity' => $link->entity_id, 'revision' => SensorSwitchControl::sourceRevision($link)];
            }
        }
        $groups = [];
        foreach ($sources as $sensorId => $links) {
            if (count($links) === 1) {
                $groups[$links[0]['client']][$sensorId] = $links[0]['entity'];
            }
        }
        $result = [];
        foreach ($groups as $clientClass => $entities) {
            $states = collect(app($clientClass)->statesFor(array_values(array_unique($entities))))->keyBy('entity_id');
            foreach ($entities as $sensorId => $entity) {
                if ($state = $states->get($entity)) {
                    $result[] = [...$this->reading($sensors[$sensorId], $state), 'sourceRevision' => $sources[$sensorId][0]['revision']];
                }
            }
        }

        return $result;
    }

    private function reading(Sensor $sensor, array $state): array
    {
        $value = null;
        $observed = null;
        $quality = 'invalid';
        try {
            $value = MeasurementValue::normalize($state['state'] ?? null);
            if ($sensor->measurementType->nature === 'binary' && ! in_array($value, ['0.000000000', '1.000000000'], true)) {
                throw new MeasurementException('measurements.errors.invalidValue');
            }
            if (($state['attributes']['unit_of_measurement'] ?? null) !== $sensor->measurementType->unit) {
                throw new MeasurementException('measurements.errors.unitMismatch');
            }
            $time = $state['last_reported'] ?? $state['last_updated'] ?? null;
            (new ZonedTimestamp)->validate('time', $time, fn () => throw new MeasurementException('measurements.errors.invalidTime'));
            $observed = CarbonImmutable::parse($time)->utc();
            if ($observed->year < 2000 || $observed->greaterThan(now()->addMinutes(5))) {
                throw new MeasurementException('measurements.errors.invalidTime');
            }
            $quality = MeasurementQuality::normalize($state['attributes']['canabicultarr_quality'] ?? null, $value, $sensor->measurementType);
            if (($state['attributes']['restored'] ?? false) === true && in_array($quality, \App\Enums\MeasurementQuality::USABLE, true)) {
                $quality = 'suspect';
            }
        } catch (MeasurementException) {
            $value = null;
            $observed = null;
        }

        return ['sensorId' => $sensor->id, 'value' => $value, 'unit' => $sensor->measurementType->unit, 'observedAt' => $observed?->toISOString(), 'quality' => $quality,
            'isStale' => $observed === null || ($sensor->expected_interval_seconds > 0 && $observed->lessThan(now()->subSeconds(max(120, $sensor->expected_interval_seconds * 2))))];
    }
}
