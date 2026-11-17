<?php

namespace App\Services;

use App\Exceptions\PlantException;
use App\Models\Plant;
use App\Models\PlantMaintenanceType;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PlantMaintenanceTypes
{
    public function records()
    {
        Gate::authorize('create', Plant::class);

        return PlantMaintenanceType::orderBy('name_key')->get();
    }

    public function remember(string $name): void
    {
        if (! in_array($name, ['topping', 'pruning', 'other'], true)) {
            PlantMaintenanceType::firstOrCreate(['name_key' => Str::lower($name)], ['name' => $name]);
        }
    }

    public function save(User $actor, array $data, ?PlantMaintenanceType $target = null): PlantMaintenanceType
    {
        try {
            return DB::transaction(function () use ($actor, $data, $target) {
                $this->authorize($actor);
                $record = $target ? PlantMaintenanceType::whereKey($target->id)->lockForUpdate()->firstOrFail() : new PlantMaintenanceType;
                if ($target && $record->version !== $data['version']) {
                    throw new PlantException('plants.errors.conflict');
                }
                if (in_array(Str::lower($data['name']), ['topping', 'pruning', 'other'], true)) {
                    throw new PlantException('plants.maintenance.nameTaken', 422);
                }
                $record->name = $data['name'];
                $record->name_key = Str::lower($data['name']);
                if ($target) {
                    $record->version++;
                }
                $record->save();
                Log::notice('plant.maintenance_type_saved', ['actor_uuid' => $actor->uuid, 'type_uuid' => $record->id]);

                return $record;
            });
        } catch (UniqueConstraintViolationException) {
            throw new PlantException('plants.maintenance.nameTaken', 422);
        }
    }

    public function remove(User $actor, PlantMaintenanceType $target, int $version): void
    {
        DB::transaction(function () use ($actor, $target, $version) {
            $this->authorize($actor);
            $record = PlantMaintenanceType::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($record->version !== $version) {
                throw new PlantException('plants.errors.conflict');
            }
            $record->delete();
            Log::notice('plant.maintenance_type_removed', ['actor_uuid' => $actor->uuid, 'type_uuid' => $record->id]);
        });
    }

    private function authorize(User $actor): void
    {
        $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        if ($current->session_version !== $actor->session_version) {
            throw new AuthenticationException;
        }
        Gate::forUser($current)->authorize('create', Plant::class);
    }
}
