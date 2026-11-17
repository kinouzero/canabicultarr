<?php

namespace App\Http\Resources;

use App\Enums\AssetCategory;
use App\Enums\AssetStatus;
use App\Models\Plant;
use App\Models\User;
use App\Services\LifecycleDefinition;
use App\Services\PlantMeasurements;
use App\Services\PlantWatering;
use App\Services\RecordPhotoGallery;
use Illuminate\Http\Request;

class PlantResource extends LocationAwareResource
{
    public function toArray(Request $request): array
    {
        return [
            ...self::phaseData($this->resource),
            'container' => $this->container_snapshot, 'id' => $this->id, 'name' => $this->resource->displayName(), 'givenName' => $this->name, 'notes' => $this->notes,
            'strain' => ['id' => $this->strain->id, 'name' => $this->strain->name, 'classification' => $this->strain->classification, 'genetics' => $this->strain->genetics],
            'harvestWeightGrams' => $this->harvest_weight_grams,
            'startedAt' => $this->started_at?->toISOString(),
            'durationSnapshot' => $this->duration_snapshot,
            'location' => $this->resource->locationDetails(),
            'watering' => PlantWatering::state($this->resource),
            'measurements' => app(PlantMeasurements::class)->state($this->resource),
            'summarySensorIds' => app(PlantMeasurements::class)->summaryIds($this->resource),
            'photoUrl' => self::photoUrl($this->resource, $request->user()),
            'hasOwnPhoto' => app(RecordPhotoGallery::class)->primaryPhoto($this->resource, $request->user()) !== null,
            'devices' => $this->devices->map(fn ($asset) => ['id' => $asset->id, 'name' => $asset->name, 'status' => $asset->status->value, 'category' => $asset->category->value, 'canActivate' => $asset->category === AssetCategory::Pump && $asset->status === AssetStatus::Active && $asset->pump_control !== null && $request->user()->can('update', $asset)])->values()->all(),
            'version' => $this->version, 'canDelete' => $request->user()->can('delete', $this->resource), 'canManage' => $this->phase !== 'archived' && $request->user()->can('update', $this->resource),
        ];
    }

    public static function photoUrl(Plant $plant, User $user): ?string
    {
        $gallery = app(RecordPhotoGallery::class);
        if ($gallery->primaryPhoto($plant, $user)) {
            return '/api/v1/plants/'.$plant->id.'/photo?version='.$plant->version;
        }
        $photo = $gallery->inheritedCover($plant);

        return $photo ? '/api/v1/documents/'.$photo->id.'/preview?version='.$photo->version : null;
    }

    public static function phaseData(Plant $plant): array
    {
        $elapsed = $plant->phase_started_at ? max(0, now()->timestamp - $plant->phase_started_at->timestamp) : null;
        $actions = LifecycleDefinition::actions($plant->phase);
        $action = collect($actions)->first(fn ($action) => $action['target'] !== $plant->phase)['id'] ?? null;

        return [
            'phase' => $plant->phase, 'availableActions' => $actions, 'stageDefinition' => LifecycleDefinition::stage($plant->phase),
            'phaseStartedAt' => $plant->phase_started_at?->toISOString(), 'phaseDueAt' => $plant->phase_due_at?->toISOString(),
            'day' => $elapsed === null ? null : intdiv($elapsed, 86400) + 1, 'week' => $elapsed !== null && $plant->phase_due_at ? intdiv($elapsed, 604800) + 1 : null,
            'isOverdue' => $plant->phase_due_at !== null && $plant->phase_due_at->isPast(),
            'nextAction' => $action,
        ];
    }
}
