<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetLocation;
use App\Models\Room;
use App\Models\Site;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AssetLocations
{
    public function resolve(array $references, User $actor): array
    {
        $resolved = [];
        foreach ($references as $reference) {
            $type = $reference['type'];
            $class = match ($type) {
                'site' => Site::class, 'room' => Room::class, 'zone' => Zone::class
            };
            $node = $class::whereKey($reference['id'])->firstOrFail();
            app(LocationAccess::class)->assertLocation($actor, ['type' => $type, 'id' => $node->id]);
            $resolved[$type.':'.$node->id] = ['site_id' => null, 'room_id' => null, 'zone_id' => null, $type.'_id' => $node->id];
        }
        ksort($resolved);

        return array_values($resolved);
    }

    /** Caller holds the hardware lock and validates every destination before mutation. */
    public function replace(Asset $asset, array $rows): void
    {
        $key = fn ($row) => ($row['site_id'] ?? '').':'.($row['room_id'] ?? '').':'.($row['zone_id'] ?? '');
        $existing = $asset->locations->keyBy(fn ($location) => $key($location->getAttributes()));
        $desired = collect($rows)->keyBy($key);
        $remove = $existing->keys()->diff($desired->keys())->map(fn ($key) => $existing[$key]->id);
        if ($remove->isNotEmpty()) {
            $asset->locations()->whereKey($remove)->delete();
        }
        $add = $desired->keys()->diff($existing->keys())->map(fn ($key) => ['id' => (string) Str::uuid(), 'asset_id' => $asset->id, ...$desired[$key]])->all();
        foreach (array_chunk($add, 100) as $batch) {
            DB::table('asset_locations')->insert($batch);
        }
        $asset->unsetRelation('locations');
        app(LocationAccess::class)->clear();
    }

    public static function relations(): array
    {
        return array_map(fn ($relation) => 'locations.'.$relation, AssetLocation::locationRelations());
    }
}
