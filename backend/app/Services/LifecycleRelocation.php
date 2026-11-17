<?php

namespace App\Services;

use App\Exceptions\PlantException;
use App\Models\LifecycleSetting;
use App\Models\Plant;
use App\Models\Room;
use App\Models\Site;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Validation\ValidationException;

class LifecycleRelocation
{
    private function destination(User $actor, array $reference): Site|Room|Zone
    {
        $model = match ($reference['type']) {
            'site' => Site::class, 'room' => Room::class, 'zone' => Zone::class,
        };
        $location = $model::whereKey($reference['id'])->lockForUpdate()->first();
        if (! $location || ! app(LocationAccess::class)->allows($actor, $reference)) {
            throw new PlantException('plants.errors.relocationUnavailable', 422);
        }

        return $location;
    }

    public function validate(User $actor, array $stages): void
    {
        foreach ($stages as $index => $stage) {
            if (empty($stage['target_location'])) {
                continue;
            }
            try {
                $this->destination($actor, $stage['target_location']);
            } catch (PlantException) {
                throw ValidationException::withMessages(['stages.'.$index.'.target_location' => 'catalog.validation.targetLocation']);
            }
        }
    }

    public function apply(User $actor, Plant $plant, LifecycleSetting $settings): ?array
    {
        if (! $settings->automatic_relocation) {
            return null;
        }
        $reference = collect($settings->stages)->firstWhere('id', $plant->phase)['target_location'] ?? null;
        if (! $reference) {
            return null;
        }
        $location = $this->destination($actor, $reference);
        $before = $plant->locationDetails();
        if ($before['type'] === $reference['type'] && $before['id'] === $location->id) {
            return null;
        }
        $plant->forceFill(['site_id' => null, 'room_id' => null, 'zone_id' => null, $reference['type'].'_id' => $location->id]);
        $plant->unsetRelation('site')->unsetRelation('room')->unsetRelation('zone');

        return $before;
    }
}
