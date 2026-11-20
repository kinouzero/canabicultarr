<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Models\LifecycleSetting;
use App\Models\Plant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class PlantWateringNeeds
{
    // Substrate probes can report less often than an integration polls.
    public const MINIMUM_FRESHNESS_SECONDS = 3600;

    public const FRESHNESS_INTERVAL_MULTIPLIER = 2;

    public static function freshnessSeconds(int $expectedIntervalSeconds): int
    {
        return max(self::MINIMUM_FRESHNESS_SECONDS, $expectedIntervalSeconds * self::FRESHNESS_INTERVAL_MULTIPLIER);
    }

    public function prepare(Collection $plants): void
    {
        $eligible = $plants->filter(fn ($plant) => in_array($plant->phase, LifecycleDefinition::allowing('watering'), true));
        foreach ($plants as $plant) {
            $plant->setRelation('wateringNeed', ['needed' => false, 'reason' => null]);
        }
        if ($eligible->isEmpty()) {
            return;
        }
        $settings = LifecycleSetting::findOrFail(1);
        $eligible = new \Illuminate\Database\Eloquent\Collection($eligible->all());
        $eligible->loadMissing([...Plant::locationRelations(), 'recentWaterings', 'devices.sensors.measurementType']);
        $sensors = $eligible->flatMap(fn ($plant) => $plant->devices->where('status', AssetStatus::Active)
            ->flatMap(fn ($device) => $device->sensors))->filter(fn ($sensor) => $sensor->is_enabled
                && $sensor->measurementType->nature === 'substrate_moisture' && $sensor->measurementType->unit === '%' && $sensor->expected_interval_seconds > 0)->unique('id');
        $sensorIds = array_fill_keys($sensors->pluck('id')->all(), true);
        $now = CarbonImmutable::now('UTC');
        $latest = app(LatestMeasurements::class)->forSensors($sensors->pluck('id'), $now);
        foreach ($eligible as $plant) {
            $zone = $plant->zone;
            $room = $zone?->room ?? $plant->room;
            $site = $room?->site ?? $plant->site;
            $days = $zone?->watering_alert_days ?? $room?->watering_alert_days ?? $site?->watering_alert_days ?? $settings->watering_alert_days;
            $threshold = $settings->watering_moisture_threshold;
            $lastWatering = $plant->recentWaterings->first()?->occurred_at;
            $reference = $lastWatering ?? $plant->started_at;
            $dueAt = $reference?->addDays($days);
            $readings = $plant->devices->where('status', AssetStatus::Active)->flatMap(fn ($device) => $device->sensors)
                ->filter(fn ($sensor) => isset($sensorIds[$sensor->id]))
                ->map(function ($sensor) use ($latest, $now, $lastWatering, $plant) {
                    $reading = $latest->get($sensor->id);
                    if (! app(MeasurementProvenance::class)->forPlant($reading, $plant->id) || $reading->unit !== '%' || ! is_numeric($reading->value)) {
                        return null;
                    }
                    $value = (float) $reading->value;
                    $freshAfter = $now->subSeconds(self::freshnessSeconds($sensor->expected_interval_seconds));
                    if (! is_finite($value) || $value < 0 || $value > 100 || $reading->observed_at->lessThan($freshAfter)
                        || ($lastWatering && $reading->observed_at->lessThanOrEqualTo($lastWatering))) {
                        return null;
                    }

                    return ['percent' => $value, 'observedAt' => $reading->observed_at->toISOString()];
                })->filter()->sortBy('percent')->values();
            $measurement = $readings->first();
            $needed = $measurement ? $measurement['percent'] < $threshold : ($dueAt && $dueAt->lessThanOrEqualTo($now));
            $plant->setRelation('wateringNeed', [
                'needed' => (bool) $needed, 'reason' => $needed ? ($measurement ? 'moisture' : 'elapsed') : null,
                'alertDays' => $days, 'moistureThreshold' => $threshold,
                'measurement' => $measurement, 'timerDueAt' => $dueAt?->toISOString(),
            ]);
        }
    }

    public function state(Plant $plant): array
    {
        if (! $plant->relationLoaded('wateringNeed')) {
            $this->prepare(collect([$plant]));
        }

        return $plant->getRelation('wateringNeed');
    }
}
