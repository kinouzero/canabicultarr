<?php

namespace App\Services;

use App\Models\Plant;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PlantDashboardHeaders
{
    public function page(Builder $query, array $criteria, Site $site): array
    {
        // Aggregate the already-authorized plant query, never the unscoped location tree.
        $counts = (clone $query)->withOnly([])->reorder()->select(['room_id', 'zone_id'])
            ->selectRaw('COUNT(*) AS total')->groupBy('room_id', 'zone_id')->toBase();
        $filtered = in_array($criteria['status'] ?? null, ['active', 'planned'], true)
            || filled($criteria['phase'] ?? null) || filled($criteria['strain_id'] ?? null) || ($criteria['due_only'] ?? false);
        if (! $filtered) {
            $hardware = app(LocationDashboardMeasurements::class)->locations($site)->select(['room_id', 'zone_id'])->selectRaw('0 AS total')->distinct()->toBase();
            $counts->unionAll($hardware);
        }
        $combined = DB::query()->fromSub($counts, 'combined_counts')
            ->selectRaw('CASE WHEN zone_id IS NULL THEN room_id ELSE NULL END AS room_id, zone_id, SUM(total) AS total')
            ->groupByRaw('CASE WHEN zone_id IS NULL THEN room_id ELSE NULL END, zone_id');
        $contexts = DB::query()->fromSub($combined, 'location_counts')
            ->leftJoin('zones', 'zones.id', '=', 'location_counts.zone_id')
            ->leftJoin('rooms', 'rooms.id', '=', DB::raw('COALESCE(location_counts.room_id, zones.room_id)'))
            ->select('location_counts.*', 'rooms.name_key AS room_name', 'zones.name_key AS zone_name')
            ->selectRaw("CASE WHEN location_counts.zone_id IS NOT NULL THEN CAST(rooms.id AS VARCHAR) WHEN location_counts.room_id IS NOT NULL THEN CAST(rooms.id AS VARCHAR) ELSE 'site' END AS root_key")
            ->selectRaw('CASE WHEN rooms.id IS NOT NULL THEN 0 ELSE 2 END AS root_order');
        $roots = DB::query()->fromSub($contexts, 'location_contexts')
            ->select('root_key', 'root_order', 'room_name')->selectRaw('COUNT(*) AS total, SUM(total) AS plants')
            ->groupBy('root_key', 'root_order', 'room_name');
        $totals = DB::query()->fromSub($roots, 'room_totals')->selectRaw('COUNT(*) AS rooms, COALESCE(SUM(plants), 0) AS plants')->first();
        $lastPage = max(1, (int) ceil($totals->rooms / 10));
        $page = min((int) ($criteria['room_page'] ?? 1), $lastPage);
        $visible = (clone $roots)->orderBy('root_order')->orderBy('room_name')->orderBy('root_key')->offset(($page - 1) * 10)->limit(10)->get();
        $roomPages = [];
        $pageSql = 'CASE root_key';
        $bindings = [];
        foreach ($visible as $room) {
            $last = max(1, (int) ceil($room->total / 10));
            $current = min((int) ($criteria['zone_pages'][$room->root_key] ?? 1), $last);
            $roomPages[$room->root_key] = ['current_page' => $current, 'last_page' => $last, 'total' => (int) $room->total, 'per_page' => 10];
            $pageSql .= ' WHEN ? THEN ?';
            array_push($bindings, $room->root_key, $current);
        }
        $pageSql .= ' ELSE 1 END';
        $ranked = DB::query()->fromSub($contexts, 'location_contexts')->whereIn('root_key', $visible->pluck('root_key'))
            ->select('root_key', 'zone_id', 'room_id', 'total')->selectRaw('ROW_NUMBER() OVER (PARTITION BY root_key ORDER BY CASE WHEN zone_id IS NULL THEN 1 ELSE 0 END, zone_name, zone_id, room_id) AS header_rank');
        $keys = $visible->isEmpty() ? collect() : DB::query()->fromSub($ranked, 'ranked_headers')
            ->whereRaw('header_rank BETWEEN (('.$pageSql.') - 1) * 10 + 1 AND ('.$pageSql.') * 10', [...$bindings, ...$bindings])
            ->get(['room_id', 'zone_id', 'total']);
        // Only bounded location identifiers and their relations are hydrated in PHP.
        $locations = (new Plant)->newCollection($keys->map(fn ($key) => (new Plant)->setRawAttributes((array) $key))->all());
        $locations->load(['room', 'zone.room']);

        return ['locations' => $locations, 'rooms' => (object) $roomPages, 'room_meta' => ['current_page' => $page, 'last_page' => $lastPage, 'total' => (int) $totals->rooms, 'per_page' => 10], 'total' => (int) $totals->plants];
    }
}
