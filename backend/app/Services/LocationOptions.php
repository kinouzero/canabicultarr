<?php

namespace App\Services;

use App\Models\Room;
use App\Models\Site;
use App\Models\Zone;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LocationOptions
{
    private function query(): Builder
    {
        // Apply each model's access scope before unioning minimal ancestor contexts.
        $sites = Site::query()->selectRaw("sites.id, 'site' AS kind, sites.id AS site_id, sites.name AS site_name, sites.name_key AS site_key, CAST(NULL AS UUID) AS room_id, NULL AS room_name, NULL AS room_code, '' AS room_key, CAST(NULL AS UUID) AS zone_id, NULL AS zone_name, NULL AS zone_code, '' AS zone_key")->toBase();
        $rooms = Room::query()->join('sites', 'sites.id', '=', 'rooms.site_id')
            ->selectRaw("rooms.id, 'room' AS kind, sites.id AS site_id, sites.name AS site_name, sites.name_key AS site_key, rooms.id AS room_id, rooms.name AS room_name, rooms.code AS room_code, rooms.name_key AS room_key, CAST(NULL AS UUID) AS zone_id, NULL AS zone_name, NULL AS zone_code, '' AS zone_key")->toBase();
        $zones = Zone::query()->join('rooms', 'rooms.id', '=', 'zones.room_id')->join('sites', 'sites.id', '=', 'rooms.site_id')
            ->selectRaw("zones.id, 'zone' AS kind, sites.id AS site_id, sites.name AS site_name, sites.name_key AS site_key, rooms.id AS room_id, rooms.name AS room_name, rooms.code AS room_code, rooms.name_key AS room_key, zones.id AS zone_id, zones.name AS zone_name, zones.code AS zone_code, zones.name_key AS zone_key")->toBase();

        return DB::query()->fromSub($sites->unionAll($rooms)->unionAll($zones), 'location_options');
    }

    public function search(array $criteria): LengthAwarePaginator
    {
        $query = $this->query();
        $search = trim($criteria['search'] ?? '');
        if (preg_match('/^(site|room|zone):([0-9a-f-]{36})$/iD', $search, $parts) && Str::isUuid($parts[2])) {
            $query->where('kind', strtolower($parts[1]))->where('id', strtolower($parts[2]));
        } elseif (Str::isUuid($search)) {
            $query->where('id', strtolower($search));
        } elseif ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower($search)).'%';
            $query->whereRaw("(site_key || ' / ' || room_key || ' / ' || zone_key LIKE ? ESCAPE '!' OR lower(COALESCE(room_code, '') || ' / ' || COALESCE(zone_code, '')) LIKE ? ESCAPE '!')", [$pattern, $pattern]);
        }

        return $query->orderBy('site_key')->orderBy('site_id')->orderBy('room_key')->orderBy('room_id')
            ->orderBy('zone_key')->orderBy('id')->paginate(CollectionPagination::size($criteria), ['*'], 'page', $criteria['page'] ?? 1);
    }

    public function find(string $kind, string $id): object
    {
        return $this->query()->where('kind', $kind)->where('id', $id)->first() ?? abort(404);
    }
}
