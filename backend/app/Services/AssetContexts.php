<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetContext;
use App\Models\Plant;
use Illuminate\Support\Facades\DB;

class AssetContexts
{
    public function capture(string $id): void
    {
        DB::transaction(function () use ($id) {
            $asset = Asset::withoutGlobalScopes()->whereKey($id)->lockForUpdate()->firstOrFail();
            $locations = array_fill_keys(['site', 'room', 'room.site', 'zone', 'zone.room', 'zone.room.site'], fn ($query) => $query->withoutGlobalScopes());
            $asset->load(['locations' => fn ($query) => $query->withoutGlobalScopes(), ...array_combine(array_map(fn ($key) => 'locations.'.$key, array_keys($locations)), array_values($locations))]);
            $plants = $asset->plants()->withoutGlobalScopes()->with(['strain', ...$locations])->reorder()->orderBy('plants.id')->get();
            $snapshot = ['asset' => ['id' => $asset->id, 'name' => $asset->name], 'locations' => $asset->locationList(),
                'plants' => $plants->map(fn ($plant) => ['id' => $plant->id, 'name' => $plant->displayName(), 'location' => $plant->locationDetails()])->all()];
            $fingerprint = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
            $previous = AssetContext::where('asset_id', $id)->orderByDesc('effective_at')->orderByDesc('id')->first();
            if ($previous && ($previous->fingerprint === $fingerprint || hash('sha256', json_encode(self::snapshot($previous->snapshot), JSON_THROW_ON_ERROR)) === $fingerprint)) {
                return;
            }
            app(LocationAccess::class)->clear();
            $context = AssetContext::create(['asset_id' => $id, 'effective_at' => now(), 'fingerprint' => $fingerprint, 'snapshot' => $snapshot]);
            $locations = [];
            foreach ($snapshot['locations'] as $location) {
                $locations[] = ['plant_id' => null, 'location' => $location];
            }
            foreach ($snapshot['plants'] as $plant) {
                $locations[] = ['plant_id' => $plant['id'], 'location' => $plant['location']];
            }
            $rows = array_map(fn ($location) => ['asset_context_id' => $context->id, 'plant_id' => $location['plant_id'],
                'site_id' => $location['location']['site']['id'] ?? null, 'room_id' => $location['location']['room']['id'] ?? null, 'zone_id' => $location['location']['zone']['id'] ?? null], $locations);
            foreach (array_chunk($rows, 100) as $batch) {
                DB::table('asset_context_locations')->insert($batch);
            }
        });
    }

    /** Normalize preserved historical snapshots without rewriting their original JSON. */
    public static function snapshot(array $snapshot): array
    {
        return ['asset' => $snapshot['asset'], 'locations' => $snapshot['locations'] ?? array_values(array_filter([$snapshot['location'] ?? null])), 'plants' => $snapshot['plants'] ?? []];
    }

    public function lockForPlant(Plant $plant, array $additional = []): void
    {
        $ids = [...$plant->devices()->withoutGlobalScopes()->pluck('assets.id')->all(), ...$additional];
        if ($ids) {
            Asset::withoutGlobalScopes()->whereKey(array_unique($ids))->orderBy('id')->lockForUpdate()->get(['id']);
        }
    }

    public function forPlant(Plant $plant): void
    {
        foreach ($plant->devices()->withoutGlobalScopes()->orderBy('assets.id')->pluck('assets.id') as $id) {
            $this->capture($id);
        }
    }
}
