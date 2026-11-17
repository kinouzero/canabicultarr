<?php

namespace App\Services;

use App\Exceptions\LocationException;
use App\Models\Document;
use App\Models\Location;
use App\Models\Room;
use App\Models\Site;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LocationAdministration
{
    public const SORT_COLUMNS = ['name' => 'name_key', 'created' => 'created_at'];

    public function search(Builder $query, array $criteria): LengthAwarePaginator
    {
        $query->with('photoDocument');
        $model = $query->getModel();
        $relation = $model->childRelation();
        if ($relation) {
            $query->withCount($relation);
        }
        $search = trim($criteria['search'] ?? '');
        if (Str::isUuid($search)) {
            $query->whereKey($search);
        } elseif ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower($search)).'%';
            $query->where(function ($query) use ($pattern, $model) {
                $query->whereRaw("name_key LIKE ? ESCAPE '!'", [$pattern]);
                if ($model instanceof Room || $model instanceof Zone) {
                    $query->orWhereRaw("upper(code) LIKE ? ESCAPE '!'", [Str::upper($pattern)]);
                }
            });
        }

        return CollectionSorting::apply($query, $criteria['sort'] ?? null, self::SORT_COLUMNS)->paginate(CollectionPagination::size($criteria), ['*'], 'page', $criteria['page'] ?? 1);
    }

    public function create(User $actor, Location $record, array $data, Site|Room|null $parent = null): Location
    {
        if ($record instanceof Room && $parent instanceof Site) {
            $record->site()->associate($parent);
        } elseif ($record instanceof Zone && $parent instanceof Room) {
            $record->room()->associate($parent);
        }

        return $this->persist($actor, $record, $data, true);
    }

    public function update(User $actor, Location $record, array $data): Location
    {
        return $this->persist($actor, $record, $data, false);
    }

    public function delete(User $actor, Location $record): void
    {
        try {
            DB::transaction(function () use ($actor, $record) {
                $current = User::whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
                $location = $record->newQuery()->whereKey($record->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($current)->authorize('delete', $location);
                $location->delete();
                app(LocationAccess::class)->clear();
            });
        } catch (QueryException $error) {
            if (in_array((string) $error->getCode(), ['23001', '23503'], true) || str_contains($error->getMessage(), 'FOREIGN KEY constraint failed')) {
                throw new LocationException('locations.errors.inUse');
            }
            throw $error;
        }
        Log::notice('location.deleted', ['actor_uuid' => $actor->uuid, 'location_uuid' => $record->id, 'kind' => $record->kind()]);
    }

    private function persist(User $actor, Location $record, array $data, bool $creating): Location
    {
        try {
            [$saved, $fields] = DB::transaction(function () use ($actor, $record, $data, $creating) {
                $current = User::whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
                if (! $creating) {
                    $record = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
                }
                Gate::forUser($current)->authorize($creating ? 'create' : 'update', $creating ? $record::class : $record);
                if ($creating) {
                    if ($record instanceof Site) {
                        app(LocationAccess::class)->assertGlobal($current);
                    } else {
                        app(LocationAccess::class)->assertLocation($current, ['type' => $record instanceof Room ? 'site' : 'room', 'id' => $record->site_id ?? $record->room_id]);
                    }
                }
                $record->fill(array_intersect_key($data, array_flip($record instanceof Site ? ['name', 'description'] : ['name', 'code', 'description'])));
                $photo = null;
                if (array_key_exists('photo_document_id', $data)) {
                    if ($data['photo_document_id'] !== null) {
                        $photo = Document::whereKey($data['photo_document_id'])->lockForUpdate()->first();
                        if (! $photo || $photo->format !== 'image' || ! in_array($photo->media_type, ['image/jpeg', 'image/png', 'image/webp'], true) || $photo->is_archived || ! Gate::forUser($current)->allows('view', $photo)) {
                            throw new LocationException('locations.errors.photoUnavailable', 422);
                        }
                    }
                    $record->photo_document_id = $photo?->id;
                }
                if (array_key_exists('watering_alert_days', $data)) {
                    $record->watering_alert_days = $data['watering_alert_days'];
                }
                $fields = array_values(array_intersect(array_keys($record->getDirty()), ['name', 'code', 'description', 'watering_alert_days', 'photo_document_id']));
                $record->save();
                if ($photo) {
                    $record->photos()->syncWithoutDetaching([$photo->id]);
                }
                $record->load('photoDocument');
                app(LocationAccess::class)->clear();
                if ($record->childRelation()) {
                    $record->loadCount($record->childRelation());
                }

                return [$record, $fields];
            });
        } catch (UniqueConstraintViolationException $error) {
            throw new LocationException(str_contains($error->getMessage(), '_code_unique') ? 'locations.errors.codeTaken' : 'locations.errors.nameTaken');
        }
        Log::notice($creating ? 'location.created' : 'location.updated', [
            'actor_uuid' => $actor->uuid, 'location_uuid' => $saved->id,
            'kind' => $saved->kind(), 'fields' => $fields,
        ]);

        return $saved;
    }
}
