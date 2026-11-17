<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetLocation;
use App\Models\Sensor;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LocationDashboardMeasurements
{
    public function locations(Site $site): Builder
    {
        // The outer asset scope authorizes every channel; avoid recursively checking that same asset.
        return AssetLocation::whereIn('asset_id', Asset::where('status', 'active')->whereHas('sensors', fn ($q) => $q->withoutGlobalScope('location_access')->where('is_enabled', true))->select('id'))
            ->where(fn ($q) => $q->where('site_id', $site->id)
                ->orWhereHas('room', fn ($room) => $room->where('site_id', $site->id))
                ->orWhereHas('zone.room', fn ($room) => $room->where('site_id', $site->id)));
    }

    public function readings(Site $site, array $contexts, int $page = 1): array
    {
        if (! $contexts) {
            return ['data' => [], 'meta' => []];
        }
        $selection = null;
        foreach ($contexts as $key => $context) {
            $ranked = Sensor::withoutGlobalScope('location_access')->where('is_enabled', true)->whereIn('asset_id', $this->locations($site)->where($context['type'].'_id', $context['id'])->select('asset_id'))
                ->select('sensors.id')->selectRaw('COUNT(*) OVER () AS total, ROW_NUMBER() OVER (ORDER BY name_key, id) AS tile_rank');
            $slice = DB::query()->fromSub($ranked->toBase(), 'ranked_tiles')->select('id', 'total', 'tile_rank')->selectRaw('? AS context_key', [$key])
                ->where(fn ($q) => $q->whereBetween('tile_rank', [($page - 1) * 20 + 1, $page * 20])->orWhere('tile_rank', 1));
            $selection = $selection ? $selection->unionAll($slice) : $slice;
        }
        $rows = $selection->get();
        $totals = $rows->pluck('total', 'context_key');
        $rows = $rows->filter(fn ($row) => $row->tile_rank >= ($page - 1) * 20 + 1 && $row->tile_rank <= $page * 20);
        $ids = $rows->pluck('id')->unique();
        $assets = Asset::whereHas('sensors', fn ($q) => $q->whereKey($ids))
            ->with(['sensors' => fn ($q) => $q->whereKey($ids)->orderBy('name_key'), 'sensors.measurementType'])->get()->keyBy('id');
        $sensors = $assets->flatMap(function ($asset) {
            foreach ($asset->sensors as $sensor) {
                $sensor->setRelation('device', $asset);
            }

            return $asset->sensors;
        })->keyBy('id');
        $metadata = app(SensorSwitchControl::class)->metadata($sensors);
        $switches = $metadata['switches'];
        $sourceRevisions = $metadata['sourceRevisions'];
        $now = CarbonImmutable::now('UTC');
        $latest = app(LatestMeasurements::class)->forSensors($assets->flatMap(fn ($asset) => $asset->sensors->pluck('id')), $now);
        $result = [];
        foreach ($contexts as $key => $context) {
            $result[$key] = [];
            foreach ($rows->where('context_key', $key) as $row) {
                $sensor = $sensors->get($row->id);
                if (! $sensor) {
                    continue;
                }
                $asset = $sensor->device;
                $reading = $latest->get($sensor->id);
                // A reading from before a move must not be presented as this room/zone's reading.
                $locations = $reading ? AssetContexts::snapshot($reading->assignmentContext?->snapshot ?? ['asset' => []])['locations'] : [];
                if (! collect($locations)->contains(fn ($location) => ($location['type'] ?? null) === $context['type'] && ($location['id'] ?? null) === $context['id'])) {
                    $reading = null;
                }
                $result[$key][] = [
                    'switchControl' => $switches[$sensor->id] ?? null,
                    'sourceRevision' => $sourceRevisions[$sensor->id] ?? null,
                    'sensorId' => $sensor->id, 'sensorName' => $sensor->name,
                    'device' => ['id' => $asset->id, 'name' => $asset->name, 'category' => $asset->category->value],
                    'measurementType' => ['name' => $sensor->measurementType->name, 'nature' => $sensor->measurementType->nature, 'decimalPlaces' => $sensor->measurementType->decimal_places],
                    'quality' => $reading?->quality, 'value' => $reading?->value, 'unit' => $reading ? $reading->unit : $sensor->measurementType->unit,
                    'observedAt' => $reading?->observed_at->toISOString(), 'isEnabled' => true,
                    'isStale' => $reading && $sensor->expected_interval_seconds > 0 && $reading->observed_at->lessThan($now->subSeconds(max(120, $sensor->expected_interval_seconds * 2))),
                ];
            }
        }

        return ['data' => $result, 'meta' => collect($contexts)->map(fn ($context, $key) => ['current_page' => $page, 'per_page' => 20, 'total' => (int) ($totals->get($key, 0))])->all()];
    }
}
