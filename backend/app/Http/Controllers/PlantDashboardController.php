<?php

namespace App\Http\Controllers;

use App\Http\Resources\PlantCardResource;
use App\Models\Plant;
use App\Models\Site;
use App\Models\Strain;
use App\Models\Zone;
use App\Services\CollectionPagination;
use App\Services\LifecycleDefinition;
use App\Services\LocationDashboardMeasurements;
use App\Services\PlantDashboardHeaders;
use App\Services\PlantDashboardSummary;
use App\Services\PlantMeasurements;
use App\Services\PlantWateringNeeds;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PlantDashboardController extends Controller
{
    public function tiles(Request $request, Site $site)
    {
        Gate::authorize('viewAny', Plant::class);
        $data = $request->validate(['type' => ['required', Rule::in(['site', 'room', 'zone'])], 'id' => ['required', 'uuid'], 'page' => ['sometimes', 'integer', 'between:1,100000']]);
        $location = match ($data['type']) {
            'site' => $site->id === $data['id'] ? $site : null,
            'room' => $site->rooms()->find($data['id']),
            'zone' => Zone::whereHas('room', fn ($q) => $q->where('site_id', $site->id))->find($data['id']),
        };
        abort_unless($location, 404);
        $key = $data['type'].':'.$data['id'];
        $page = app(LocationDashboardMeasurements::class)->readings($site, [$key => $data], $data['page'] ?? 1);

        return response()->json(['data' => $page['data'][$key], 'meta' => $page['meta'][$key]]);
    }

    public function show(Request $request, Site $site)
    {
        Gate::authorize('viewAny', Plant::class);
        $criteria = $request->validate([
            'grouped' => ['sometimes', 'boolean'],
            'metadata_only' => ['sometimes', 'boolean'],
            'preview_groups' => ['sometimes', 'array', 'max:25'],
            'preview_groups.*' => ['string', 'distinct', 'regex:/^(site|room|zone):[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di'],
            'room_page' => ['sometimes', 'integer', 'between:1,100000'],
            'zone_pages' => ['sometimes', 'array', 'max:1000'],
            'zone_pages.*' => ['integer', 'between:1,100000'],
            'pages' => ['sometimes', 'array', 'max:1000'],
            'pages.*' => ['integer', 'between:1,100000'],
            'status' => ['sometimes', Rule::in(['active', 'planned', 'all'])],
            'per_page' => CollectionPagination::rules(), 'page' => ['sometimes', 'integer', 'between:1,100000'],
            'phase' => ['sometimes', 'nullable', Rule::in(array_diff(LifecycleDefinition::ids(), ['archived']))],
            'strain_id' => ['sometimes', 'nullable', 'uuid'],
            'due_only' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(['name_asc', 'name_desc', 'phase_asc', 'phase_desc', 'due_asc', 'due_desc'])],
        ]);
        $perPage = CollectionPagination::size($criteria);
        $preview = ($criteria['grouped'] ?? false) && isset($criteria['preview_groups']) && ! ($criteria['metadata_only'] ?? false);
        $base = Plant::where('phase', '!=', 'archived')->where(fn ($q) => $q->where('site_id', $site->id)->orWhereHas('room', fn ($r) => $r->where('site_id', $site->id))->orWhereHas('zone.room', fn ($r) => $r->where('site_id', $site->id)));
        // Options cover visible started and planned plants in this site, independently of filters/pages.
        $strains = $preview ? null : Strain::whereIn('id', (clone $base)->select('strain_id'))->orderBy('name_key')->orderBy('id')->get(['id', 'name']);
        $query = clone $base;
        foreach (['phase', 'strain_id'] as $field) {
            if (filled($criteria[$field] ?? null)) {
                $query->where($field, $criteria[$field]);
            }
        }
        if ($preview) {
            $keys = $criteria['preview_groups'];
            if ($keys) {
                $query->whereRaw("COALESCE('zone:' || CAST(plants.zone_id AS VARCHAR), 'room:' || CAST(plants.room_id AS VARCHAR), ?) IN (".implode(', ', array_fill(0, count($keys), '?')).')', ['site:'.$site->id, ...$keys]);
            } else {
                $query->whereRaw('1=0');
            }
        }
        $watering = app(PlantWateringNeeds::class);
        $summary = app(PlantDashboardSummary::class)->apply($query, ! $preview, (bool) ($criteria['due_only'] ?? false));
        $status = $criteria['status'] ?? 'active';
        if ($status === 'active') {
            $query->where('phase', '!=', 'planned');
        } elseif ($status === 'planned') {
            $query->where('phase', 'planned');
        }
        $query->with([...Plant::locationRelations(), 'strain', 'photoDocument', 'recentWaterings'])
            ->orderByRaw('CASE WHEN zone_id IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN room_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('room_id')->orderBy('zone_id');
        $sort = $criteria['sort'] ?? 'name_asc';
        $direction = str_ends_with($sort, '_desc') ? 'desc' : 'asc';
        if (str_starts_with($sort, 'due_')) {
            $query->orderByRaw('CASE WHEN phase_due_at IS NULL THEN 1 ELSE 0 END')->orderBy('phase_due_at', $direction);
        } elseif (str_starts_with($sort, 'phase_')) {
            $cases = implode(' ', array_map(fn ($phase, $rank) => "WHEN '$phase' THEN $rank", LifecycleDefinition::ids(), array_keys(LifecycleDefinition::ids())));
            $query->orderByRaw("CASE phase $cases ELSE 99 END $direction");
        }
        $query->orderBy('name_key', str_starts_with($sort, 'name_') ? $direction : 'asc')->orderBy('id');
        $additional = ['site' => ['id' => $site->id, 'name' => $site->name]];
        if (! $preview) {
            $additional += ['summary' => $summary, 'filterOptions' => ['strains' => $strains]];
        }
        if ($criteria['grouped'] ?? false) {
            $headers = $preview ? null : app(PlantDashboardHeaders::class)->page($query, $criteria, $site);
            $locations = $headers ? $headers['locations'] : (clone $query)->withOnly(['room', 'zone.room'])->reorder()->select(['room_id', 'zone_id'])
                ->selectRaw('COUNT(*) AS total')->groupBy('room_id', 'zone_id')->get();
            if ($headers) {
                $additional += ['rooms' => $headers['rooms'], 'room_meta' => $headers['room_meta']];
            }
            $groups = [];
            $pages = [];
            $grammar = $query->getQuery()->getGrammar();
            $ordering = implode(', ', array_map(fn ($order) => ($order['type'] ?? '') === 'Raw'
                ? $order['sql'] : $grammar->wrap($order['column']).' '.$order['direction'], $query->getQuery()->orders));
            $ranked = (clone $query)->reorder()->select(['plants.id', 'plants.room_id', 'plants.zone_id'])
                ->selectRaw("ROW_NUMBER() OVER (PARTITION BY room_id, zone_id ORDER BY $ordering) AS group_rank");
            foreach ($locations as $location) {
                $key = $location->zone_id ? 'zone:'.$location->zone_id : ($location->room_id ? 'room:'.$location->room_id : 'site:'.$site->id);
                if (isset($criteria['preview_groups']) && ! in_array($key, $criteria['preview_groups'], true)) {
                    continue;
                }
                $total = (int) $location->total;
                $lastPage = max(1, (int) ceil($total / $perPage));
                $page = min((int) ($criteria['pages'][$key] ?? 1), $lastPage);
                if ($page > 1) {
                    $pages[$key] = $page;
                }
                $room = $location->room ?? $location->zone?->room;
                $context = ['type' => $location->zone_id ? 'zone' : ($room ? 'room' : 'site'), 'id' => $location->zone_id ?? $room?->id ?? $site->id,
                    'site' => ['id' => $site->id, 'name' => $site->name],
                    'room' => $room ? ['id' => $room->id, 'name' => $room->name] : null,
                    'zone' => $location->zone ? ['id' => $location->zone->id, 'name' => $location->zone->name] : null];
                $groups[$key] = ['location' => $context, 'current_page' => $page, 'last_page' => $lastPage, 'total' => $total, 'per_page' => $perPage];
            }

            if ($headers) {
                $contexts = array_map(fn ($group) => $group['location'], $groups);
                foreach ($groups as $group) {
                    if ($group['location']['room']) {
                        $room = $group['location']['room'];
                        $contexts['room:'.$room['id']] = ['type' => 'room', 'id' => $room['id']];
                    }
                }
                $tiles = app(LocationDashboardMeasurements::class)->readings($site, $contexts);
                $additional['locationReadings'] = (object) $tiles['data'];
                $additional['locationReadingsMeta'] = (object) $tiles['meta'];
            }

            // Rank in SQL, then eager-load only the visible cards in one batch.
            $selection = DB::query()->fromSub($ranked->toBase(), 'ranked_plants');
            if ($pages) {
                // A flat CASE grows only with explicit non-first-page requests, not all zones.
                $pageSql = "CASE COALESCE('zone:' || CAST(zone_id AS VARCHAR), 'room:' || CAST(room_id AS VARCHAR), ?)";
                $bindings = ['site:'.$site->id];
                foreach ($pages as $key => $page) {
                    $pageSql .= ' WHEN ? THEN ?';
                    array_push($bindings, $key, $page);
                }
                $pageSql .= ' ELSE 1 END';
                $selection->whereRaw('group_rank BETWEEN (('.$pageSql.') - 1) * ? + 1 AND ('.$pageSql.') * ?', [
                    ...$bindings, $perPage, ...$bindings, $perPage,
                ]);
            } else {
                $selection->where('group_rank', '<=', $perPage);
            }
            // Restrict card ranking to the same bounded headers as the response.
            if ($headers) {
                $keys = array_keys($groups);
                $selection->whereRaw("COALESCE('zone:' || CAST(zone_id AS VARCHAR), 'room:' || CAST(room_id AS VARCHAR), ?) IN (".implode(', ', array_fill(0, count($keys), '?')).')', ['site:'.$site->id, ...$keys]);
            }
            $ids = ! $groups || ($criteria['metadata_only'] ?? false) ? collect() : $selection->pluck('id');
            $records = (clone $query)->whereKey($ids)->get();

            $watering->prepare($records);
            app(PlantMeasurements::class)->prepare($records);

            return PlantCardResource::collection($records)->additional([
                ...$additional, 'groups' => (object) $groups,
                'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => $headers ? $headers['total'] : (int) $locations->sum('total'), 'per_page' => $perPage],
            ]);
        }
        $records = $query->paginate($perPage, ['*'], 'page', $criteria['page'] ?? 1);

        $watering->prepare($records->getCollection());
        app(PlantMeasurements::class)->prepare($records->getCollection());

        return PlantCardResource::collection($records)->additional($additional);
    }
}
