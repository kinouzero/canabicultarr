<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Models\LifecycleSetting;
use App\Models\Measurement;
use App\Models\PlantEvent;
use App\Models\Room;
use App\Models\Sensor;
use App\Models\Site;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class PlantWateringSelection
{
    // Match PlantWateringNeeds without hydrating every plant or exporting its ID to PHP.
    public function due(Builder $plants, CarbonImmutable $asOf): QueryBuilder
    {
        $settings = LifecycleSetting::findOrFail(1);
        $connection = $plants->getConnection();
        $driver = $connection->getDriverName();
        $microseconds = (int) $asOf->format('Uu');
        $now = $asOf->format('Y-m-d H:i:s.u');
        $base = (clone $plants)->withoutEagerLoads()->reorder()->select([
            'plants.id', 'plants.phase', 'plants.phase_due_at', 'plants.started_at', 'plants.site_id', 'plants.room_id', 'plants.zone_id',
        ])->whereIn('plants.phase', LifecycleDefinition::allowing('watering'))
            ->selectSub(PlantEvent::select('occurred_at')->whereColumn('plant_id', 'plants.id')->where('kind', 'watered')
                ->orderByDesc('occurred_at')->orderByDesc('plant_version')->limit(1), 'last_watered_at');
        $context = $connection->query()->fromSub($base->toBase(), 'watering_base')->select('watering_base.*')
            ->leftJoinSub(Zone::select('id', 'room_id', 'watering_alert_days'), 'alert_zone', 'alert_zone.id', '=', 'watering_base.zone_id')
            ->leftJoinSub(Room::select('id', 'site_id', 'watering_alert_days'), 'alert_room', function ($join) {
                $join->whereRaw('alert_room.id = COALESCE(alert_zone.room_id, watering_base.room_id)');
            })
            ->leftJoinSub(Site::select('id', 'watering_alert_days'), 'alert_site', function ($join) {
                $join->whereRaw('alert_site.id = COALESCE(alert_room.site_id, watering_base.site_id)');
            })
            ->selectRaw('COALESCE(alert_zone.watering_alert_days, alert_room.watering_alert_days, alert_site.watering_alert_days, ?) AS alert_days', [$settings->watering_alert_days]);

        // Each eligible sensor probes the existing (sensor_id, observed_at) index.
        // The latest visible usable non-future sample wins; freshness and unit checks remain separate.
        $sensors = Sensor::select('sensors.id as sensor_id', 'sensors.asset_id', 'sensors.expected_interval_seconds')
            ->where('is_enabled', true)->where('expected_interval_seconds', '>', 0)
            ->whereHas('device', fn ($asset) => $asset->where('status', AssetStatus::Active))
            ->whereHas('measurementType', fn ($type) => $type->where('nature', 'substrate_moisture')->where('unit', '%'))
            ->selectSub(Measurement::usable()->select('measurements.observed_at')->whereColumn('sensor_id', 'sensors.id')
                ->where('observed_at', '<=', $now)->orderByDesc('observed_at')->limit(1), 'latest_observed_at');
        $minimum = $connection->query()->fromSub($sensors, 'moisture_sensors')
            ->join('plant_device', 'plant_device.asset_id', '=', 'moisture_sensors.asset_id')
            ->join('measurements as moisture_reading', function ($join) {
                $join->on('moisture_reading.sensor_id', '=', 'moisture_sensors.sensor_id')
                    ->on('moisture_reading.observed_at', '=', 'moisture_sensors.latest_observed_at');
            })
            ->whereColumn('plant_device.plant_id', 'watering_context.id')
            ->whereExists(fn ($context) => $context->selectRaw('1')->from('asset_context_locations')->whereColumn('asset_context_id', 'moisture_reading.asset_context_id')->whereColumn('asset_context_locations.plant_id', 'watering_context.id'))
            ->where('moisture_reading.unit', '%')->whereRaw('CAST(moisture_reading.value AS NUMERIC) BETWEEN 0 AND 100')
            ->where(function ($query) use ($driver) {
                $query->whereNull('watering_context.last_watered_at');
                if ($driver === 'sqlite') {
                    $query->orWhereRaw($this->sqliteMicroseconds('moisture_reading.observed_at').' > '.$this->sqliteMicroseconds('watering_context.last_watered_at'));
                } else {
                    $query->orWhereColumn('moisture_reading.observed_at', '>', 'watering_context.last_watered_at');
                }
            });
        $multiplier = PlantWateringNeeds::FRESHNESS_INTERVAL_MULTIPLIER;
        $minimumAge = PlantWateringNeeds::MINIMUM_FRESHNESS_SECONDS;
        $interval = "CASE WHEN moisture_sensors.expected_interval_seconds * $multiplier < $minimumAge THEN $minimumAge ELSE moisture_sensors.expected_interval_seconds * $multiplier END";
        $fresh = match ($driver) {
            'pgsql' => "moisture_reading.observed_at >= CAST(? AS TIMESTAMPTZ) - ($interval) * INTERVAL '1 second'",
            'sqlite' => $this->sqliteMicroseconds('moisture_reading.observed_at')." >= ? - ($interval) * 1000000",
            default => throw new \LogicException('Watering selection requires PostgreSQL or SQLite.'),
        };
        $minimum->whereRaw($fresh, [$driver === 'sqlite' ? $microseconds : $now])->selectRaw('MIN(CAST(moisture_reading.value AS NUMERIC))');
        $candidates = $connection->query()->fromSub($context, 'watering_context')
            ->select('watering_context.*')->selectSub($minimum, 'moisture');
        $elapsed = match ($driver) {
            'pgsql' => "COALESCE(last_watered_at, started_at) + alert_days * INTERVAL '1 day' <= CAST(? AS TIMESTAMPTZ)",
            'sqlite' => $this->sqliteMicroseconds('COALESCE(last_watered_at, started_at)').' + alert_days * 86400000000 <= ?',
        };

        return $connection->query()->fromSub($candidates, 'watering_candidates')->select('watering_candidates.id')
            ->whereRaw('COALESCE(moisture < CAST(? AS NUMERIC), '.$elapsed.')', [$settings->watering_moisture_threshold, $driver === 'sqlite' ? $microseconds : $now]);
    }

    private function sqliteMicroseconds(string $timestamp): string
    {
        // SQLite Julian dates round timestamps; split canonical UTC seconds/fractions instead.
        return "(CAST(strftime('%s', substr($timestamp, 1, 19)) AS INTEGER) * 1000000 + CAST(substr(substr($timestamp, 21) || '000000', 1, 6) AS INTEGER))";
    }
}
