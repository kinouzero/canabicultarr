<?php

namespace App\Services;

use App\Enums\MeasurementNature;
use App\Exceptions\SensorException;
use App\Models\MeasurementType;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MeasurementTypeAdministration
{
    public const SORT_COLUMNS = ['name' => 'name_key', 'created' => 'created_at', 'channels' => 'sensors_count'];

    public function search(array $criteria): LengthAwarePaginator
    {
        $query = MeasurementType::withCount('sensors');
        $search = trim($criteria['search'] ?? '');
        if (Str::isUuid($search)) {
            $query->whereKey($search);
        } elseif ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower($search)).'%';
            $query->where(fn ($q) => $q->whereRaw("name_key LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("code LIKE ? ESCAPE '!'", [$pattern]));
        }

        return CollectionSorting::apply($query, $criteria['sort'] ?? null, self::SORT_COLUMNS)->paginate(CollectionPagination::size($criteria), ['*'], 'page', $criteria['page'] ?? 1);
    }

    public function save(User $actor, array $data, ?MeasurementType $target = null): MeasurementType
    {
        $creating = $target === null;
        try {
            [$type, $fields] = DB::transaction(function () use ($actor, $data, $target, $creating) {
                $current = User::whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
                $type = $creating ? new MeasurementType : MeasurementType::whereKey($target->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($current)->authorize($creating ? 'create' : 'update', $creating ? MeasurementType::class : $type);
                $type->fill(Arr::only($data, ['name', 'code', 'unit', 'decimal_places', 'description', 'nature']));
                if (! $creating && $type->isDirty(['code', 'unit', 'decimal_places']) && $type->sensors()->exists()) {
                    throw new SensorException('measurementTypes.errors.inUse');
                }
                if (! MeasurementNature::tryFrom($type->nature)) {
                    throw new SensorException('measurementTypes.validation.natureInvalid', 422);
                }
                if ($type->nature === 'substrate_moisture' && $type->unit !== '%') {
                    throw new SensorException('measurementTypes.validation.moistureInvalid', 422);
                }
                $fields = array_values(array_intersect(array_keys($type->getDirty()), ['name', 'code', 'unit', 'decimal_places', 'description', 'nature']));
                $type->save();
                $type->loadCount('sensors');

                return [$type, $fields];
            });
        } catch (UniqueConstraintViolationException) {
            throw new SensorException('measurementTypes.errors.codeTaken');
        }
        Log::notice($creating ? 'measurement_type.created' : 'measurement_type.updated', ['actor_uuid' => $actor->uuid, 'measurement_type_uuid' => $type->id, 'fields' => $fields]);

        return $type;
    }
}
