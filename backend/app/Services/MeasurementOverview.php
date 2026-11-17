<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Measurement;
use App\Models\Sensor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MeasurementOverview
{
    public const SORT_COLUMNS = ['name' => 'name_key', 'created' => 'created_at', 'observed' => 'latest_observed_at'];

    public function read(array $filters): array
    {
        return DB::transaction(function () use ($filters) {
            if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return $this->page($filters);
        });
    }

    private function page(array $filters): array
    {
        $now = CarbonImmutable::now('UTC');
        $query = app(SensorAdministration::class)->query($filters);
        if (! empty($filters['site_id'])) {
            $id = $filters['site_id'];
            $atSite = fn ($q) => $q->where('site_id', $id)->orWhereHas('room', fn ($r) => $r->where('site_id', $id))->orWhereHas('zone.room', fn ($r) => $r->where('site_id', $id));
            $query->whereHas('device', fn ($q) => $q->whereHas('locations', $atSite)->orWhereHas('plants', $atSite));
        }
        if ($filters['pinned_only'] ?? false) {
            $query->whereIn('id', $filters['sensor_ids'] ?? []);
        }
        $counts = array_fill_keys(['fresh', 'stale', 'missing', 'unknown', 'disabled'], 0);
        $page = $filters['page'] ?? 1;
        $perPage = CollectionPagination::size($filters);
        $query->setEagerLoads([])->select(['sensors.id', 'sensors.name_key', 'sensors.created_at', 'sensors.is_enabled', 'sensors.expected_interval_seconds'])
            ->selectSub(Measurement::usable()->select('observed_at')->whereColumn('sensor_id', 'sensors.id')->where('observed_at', '<=', $now)->orderByDesc('observed_at')->limit(1), 'latest_observed_at')
            ->selectSub(Asset::select('status')->whereColumn('assets.id', 'sensors.asset_id')->limit(1), 'asset_status');
        $stale = DB::getDriverName() === 'pgsql'
            ? "latest_observed_at < CAST(? AS timestamptz) - GREATEST(120, expected_interval_seconds * 2) * INTERVAL '1 second'"
            : 'julianday(latest_observed_at) < julianday(?) - max(120, expected_interval_seconds * 2) / 86400.0';
        $health = DB::query()->fromSub($query->toBase(), 'channel_health')->select('*')->selectRaw("CASE WHEN is_enabled = false OR asset_status <> 'active' THEN 'disabled' WHEN latest_observed_at IS NULL THEN 'missing' WHEN expected_interval_seconds IS NULL THEN 'unknown' WHEN $stale THEN 'stale' ELSE 'fresh' END AS status", [$now->format('Y-m-d H:i:s.u')]);
        foreach (DB::query()->fromSub(clone $health, 'channel_counts')->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->get() as $row) {
            $counts[$row->status] = (int) $row->total;
        }
        $matchingQuery = DB::query()->fromSub($health, 'matching_channels')->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']));
        $matching = (clone $matchingQuery)->count();
        $pageRows = CollectionSorting::apply($matchingQuery, $filters['sort'] ?? null, self::SORT_COLUMNS)->forPage($page, $perPage)->get(['id', 'status']);
        $sensors = Sensor::with(SensorAdministration::relations())->whereKey($pageRows->pluck('id'))->get()->keyBy('id');
        $latest = app(LatestMeasurements::class)->forSensors($pageRows->pluck('id'), $now);
        $rows = $pageRows->filter(fn ($row) => $sensors->has($row->id))->map(fn ($row) => ['sensor' => $sensors[$row->id], 'latest' => $latest->get($row->id), 'status' => $row->status])->all();

        return ['rows' => $rows, 'summary' => ['total' => array_sum($counts), ...$counts], 'generatedAt' => $now->toISOString(),
            'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($matching / $perPage)), 'per_page' => $perPage, 'total' => $matching]];
    }
}
