<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Enums\MeasurementNature;
use App\Models\Plant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as ModelCollection;
use Illuminate\Support\Collection;

class PlantMeasurements
{
    public function prepare(Collection $plants): void
    {
        if ($plants->isEmpty()) {
            return;
        }
        (new ModelCollection($plants->all()))->loadMissing('devices.sensors.measurementType');
        $ids = $plants->flatMap(fn ($plant) => $plant->devices->flatMap(fn ($device) => $device->sensors->pluck('id')))->unique();
        $sensors = $plants->flatMap(fn ($plant) => $plant->devices->flatMap(function ($device) {
            foreach ($device->sensors as $sensor) {
                $sensor->setRelation('device', $device);
            }

            return $device->sensors;
        }));
        $metadata = app(SensorSwitchControl::class)->metadata($sensors);
        $switches = $metadata['switches'];
        $sourceRevisions = $metadata['sourceRevisions'];
        $now = CarbonImmutable::now('UTC');
        $latest = app(LatestMeasurements::class)->forSensors($ids, $now);
        foreach ($plants as $plant) {
            $measurements = $plant->devices->sortBy('name')->flatMap(fn ($device) => $device->sensors->sortBy('name')->map(function ($sensor) use ($device, $latest, $now, $plant, $switches, $sourceRevisions) {
                $reading = $latest->get($sensor->id);
                if (! app(MeasurementProvenance::class)->forPlant($reading, $plant->id)) {
                    $reading = null;
                }

                $interval = $sensor->expected_interval_seconds;
                $freshnessSeconds = $sensor->measurementType->nature === MeasurementNature::SubstrateMoisture->value && $sensor->measurementType->unit === '%'
                    ? PlantWateringNeeds::freshnessSeconds((int) $interval)
                    : max(120, $interval * 2);

                return [
                    'switchControl' => $switches[$sensor->id] ?? null,
                    'sourceRevision' => $sourceRevisions[$sensor->id] ?? null,
                    'sensorId' => $sensor->id, 'sensorName' => $sensor->name,
                    'device' => ['id' => $device->id, 'name' => $device->name, 'category' => $device->category->value],
                    'measurementType' => ['name' => $sensor->measurementType->name, 'nature' => $sensor->measurementType->nature, 'decimalPlaces' => $sensor->measurementType->decimal_places],
                    'quality' => $reading?->quality, 'value' => $reading?->value, 'unit' => $reading ? $reading->unit : $sensor->measurementType->unit,
                    'observedAt' => $reading?->observed_at->toISOString(),
                    'isEnabled' => $sensor->is_enabled && $device->status === AssetStatus::Active,
                    'isStale' => $reading && $interval > 0 && $reading->observed_at->lessThan($now->subSeconds($freshnessSeconds)),
                ];
            }))->values()->all();
            $plant->setRelation('latestMeasurements', $measurements);
        }
    }

    public function summaryIds(Plant $plant): ?array
    {
        return $plant->summary_sensor_ids === null ? null : array_values(array_intersect($plant->summary_sensor_ids, array_column($this->state($plant), 'sensorId')));
    }

    public function state(Plant $plant): array
    {
        if (! $plant->relationLoaded('latestMeasurements')) {
            $this->prepare(collect([$plant]));
        }

        return $plant->getRelation('latestMeasurements');
    }
}
