<?php

namespace App\Services;

use App\Models\Measurement;
use App\Models\Sensor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class LatestMeasurements
{
    public function forSensors(Collection $ids, CarbonImmutable $now): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }
        // Probe the (sensor_id, observed_at) index once per visible sensor instead
        // of grouping its entire history. Both queries retain location scopes.
        $latestIds = Sensor::whereKey($ids->unique()->values())->select('sensors.id')
            ->selectSub(Measurement::usable()->select('measurements.id')->whereColumn('sensor_id', 'sensors.id')
                ->where('observed_at', '<=', $now->format('Y-m-d H:i:s.u'))
                ->orderByDesc('observed_at')->limit(1), 'latest_id')
            ->get()->pluck('latest_id')->filter();

        return Measurement::whereIn('id', $latestIds)->get()->keyBy('sensor_id');
    }
}
