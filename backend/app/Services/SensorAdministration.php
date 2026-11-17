<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Exceptions\SensorException;
use App\Models\Asset;
use App\Models\MeasurementType;
use App\Models\Sensor;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SensorAdministration
{
    public const SORT_COLUMNS = ['name' => 'name_key', 'created' => 'created_at', 'interval' => 'expected_interval_seconds'];

    public static function relations(string $prefix = ''): array
    {
        return [$prefix.'measurementType', $prefix.'device' => fn ($query) => $query->with(Asset::assignmentRelations())->withCount('plants')];
    }

    public function search(array $criteria): LengthAwarePaginator
    {
        return CollectionSorting::apply($this->query($criteria), $criteria['sort'] ?? null, self::SORT_COLUMNS)->paginate(CollectionPagination::size($criteria), ['*'], 'page', $criteria['page'] ?? 1);
    }

    public function query(array $criteria): Builder
    {
        $query = Sensor::with(self::relations());
        if (! empty($criteria['device_id'])) {
            $query->where('asset_id', $criteria['device_id']);
        }
        if (! empty($criteria['measurement_type_id'])) {
            $query->where('measurement_type_id', $criteria['measurement_type_id']);
        }
        if (isset($criteria['is_enabled'])) {
            $query->where('is_enabled', $criteria['is_enabled']);
        }
        $pattern = self::searchPattern($criteria['search'] ?? '');
        if ($pattern !== null) {
            self::matchSearch($query, $pattern);
        }

        return $query;
    }

    public static function searchPattern(string $search): ?string
    {
        $search = trim($search);

        return $search === '' ? null : '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower($search)).'%';
    }

    public static function matchSearch(Builder $query, string $pattern): Builder
    {
        return $query->where(fn ($match) => $match->whereRaw("name_key LIKE ? ESCAPE '!'", [$pattern])
            ->orWhereRaw("channel_key LIKE ? ESCAPE '!'", [$pattern])
            ->orWhereHas('device', fn ($device) => $device->whereRaw("name_key LIKE ? ESCAPE '!'", [$pattern]))
            ->orWhereHas('measurementType', fn ($type) => $type->whereRaw("code LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("name_key LIKE ? ESCAPE '!'", [$pattern])));
    }

    public function save(User $actor, array $data, ?Sensor $target = null): Sensor
    {
        $creating = $target === null;
        try {
            [$sensor, $fields] = DB::transaction(function () use ($actor, $data, $target, $creating) {
                $current = User::whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
                $sensor = $creating ? new Sensor : Sensor::whereKey($target->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($current)->authorize($creating ? 'create' : 'update', $creating ? Sensor::class : $sensor);
                if ($creating) {
                    $device = Asset::whereKey($data['device_id'])->lockForUpdate()->firstOrFail();
                    app(LocationAccess::class)->assertRecord($current, $device);
                    if ($device->status === AssetStatus::Retired) {
                        throw new SensorException('sensors.errors.deviceRetired', 422);
                    }
                    // Serialize type edits with channel creation before fixing measurement semantics.
                    $type = MeasurementType::whereKey($data['measurement_type_id'])->lockForUpdate()->firstOrFail();
                    $sensor->device()->associate($device);
                    $sensor->measurementType()->associate($type);
                    $sensor->channel_key = $data['channel_key'];
                }
                $sensor->fill(Arr::only($data, ['name', 'is_enabled', 'expected_interval_seconds', 'description']));
                $fields = array_values(array_intersect(array_keys($sensor->getDirty()), ['name', 'asset_id', 'measurement_type_id', 'channel_key', 'is_enabled', 'expected_interval_seconds', 'description']));
                $sensor->save();

                return [$sensor->load(self::relations()), $fields];
            });
        } catch (UniqueConstraintViolationException) {
            throw new SensorException('sensors.errors.channelTaken');
        }
        Log::notice($creating ? 'sensor.created' : 'sensor.updated', ['actor_uuid' => $actor->uuid, 'sensor_uuid' => $sensor->id, 'fields' => $fields]);

        return $sensor;
    }
}
