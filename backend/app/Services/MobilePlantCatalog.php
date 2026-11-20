<?php

namespace App\Services;

use App\Models\Document;
use App\Models\InventoryItem;
use App\Models\Plant;
use App\Models\Strain;
use App\Models\User;

final class MobilePlantCatalog
{
    private const LIMIT = 100;

    public function read(User $actor, array $siteIds, bool $checkOnly, LocationOptions $locationOptions): array
    {
        $query = Plant::query()->where('phase', '!=', 'archived')
            ->where(function ($query) use ($siteIds) {
                $query->whereIn('site_id', $siteIds)
                    ->orWhereHas('room', fn ($room) => $room->whereIn('site_id', $siteIds))
                    ->orWhereHas('zone.room', fn ($room) => $room->whereIn('site_id', $siteIds));
            })
            ->orderBy('id')->limit(self::LIMIT + 1);
        $columns = ['id', 'site_id', 'room_id', 'zone_id'];

        if (! $checkOnly) {
            $columns = [...$columns, 'name', 'strain_id', 'phase', 'version', 'phase_started_at', 'phase_due_at', 'duration_snapshot'];
            $query->with(['strain:id,name', 'site:id,name', 'room:id,site_id,name', 'room.site:id,name',
                'zone:id,room_id,name', 'zone.room:id,site_id,name', 'zone.room.site:id,name']);
        }

        $records = $query->get($columns);
        $plants = $records->take(self::LIMIT);
        // Names and phases may go stale without affecting the user's access.
        $scope = $plants->map(fn (Plant $plant) => $plant->only(['id', 'site_id', 'room_id', 'zone_id']))->all();
        $canCreate = $actor->can('create', Plant::class);
        $locations = $canCreate ? $locationOptions->offlineChoices($actor, $siteIds, self::LIMIT) : [];
        $strains = $locations ? Strain::query()->where('is_archived', false)->orderBy('name_key')->orderBy('id')
            ->limit(self::LIMIT)->get(['id', 'name'])->toArray() : [];
        $canUpload = $actor->can('create', Document::class);
        $canConsume = $actor->can('create', InventoryItem::class);
        $version = hash('sha256', json_encode([$canCreate, $scope, $locations, $strains, $canUpload, $canConsume, $actor->inventory_location_access], JSON_THROW_ON_ERROR));
        if ($checkOnly) {
            return ['version' => $version];
        }

        $stages = array_column(LifecycleDefinition::stages(), null, 'id');
        app(LocationAccess::class)->prepare($actor, $plants);
        $recipes = $canCreate && $canConsume ? app(MobileWateringRecipes::class)->read($plants, $stages, app(FertilizerSchedule::class)) : [];

        return [
            'version' => $version,
            'truncated' => $records->count() > self::LIMIT,
            'creation' => ['locations' => $locations, 'strains' => $strains],
            'plants' => $plants->map(function (Plant $plant) use ($stages, $actor, $recipes, $canUpload) {
                $location = $plant->locationDetails();
                $canUpdate = $actor->can('update', $plant);

                return [
                    'id' => $plant->id,
                    'name' => $plant->displayName(),
                    'strain' => $plant->strain->name,
                    'phase' => $plant->phase,
                    'phaseName' => $stages[$plant->phase]['name'] ?? null,
                    'version' => $plant->version,
                    'canPhotograph' => $canUpdate && $canUpload && $location !== null,
                    'canMaintain' => ($stages[$plant->phase]['maintenance'] ?? false) && $canUpdate,
                    'waterLiters' => $stages[$plant->phase]['water_liters'] ?? null,
                    'wateringRecipe' => $canUpdate ? ($recipes[$plant->id] ?? null) : null,
                    'canWater' => ($stages[$plant->phase]['watering'] ?? false) && $canUpdate,
                    'location' => array_values(array_filter([
                        $location['site']['name'] ?? null, $location['room']['name'] ?? null, $location['zone']['name'] ?? null,
                    ])),
                ];
            })->all(),
        ];
    }
}
