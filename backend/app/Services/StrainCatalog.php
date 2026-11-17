<?php

namespace App\Services;

use App\Exceptions\CatalogException;
use App\Models\Document;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\LifecycleSetting;
use App\Models\Plant;
use App\Models\Strain;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StrainCatalog
{
    public const SORTS = ['name_asc', 'name_desc', 'flowering_asc', 'flowering_desc', 'stock_asc', 'stock_desc', 'yield_asc', 'yield_desc'];

    public function settings(): LifecycleSetting
    {
        return LifecycleSetting::findOrFail(1);
    }

    private function lockActor(User $actor): User
    {
        $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        if ($current->session_version !== $actor->session_version) {
            throw new AuthenticationException;
        }

        return $current;
    }

    public function search(array $criteria)
    {
        $query = Strain::query()->withSeedStock();
        $status = $criteria['status'] ?? 'active';
        if ($status !== 'all') {
            $query->where('is_archived', $status === 'archived');
        }
        if (filled($criteria['search'] ?? null)) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower(trim($criteria['search']))).'%';
            $query->whereRaw("name_key LIKE ? ESCAPE '!'", [$pattern]);
        }

        $sort = $criteria['sort'] ?? 'name_asc';
        $direction = str_ends_with($sort, '_desc') ? 'desc' : 'asc';
        if (str_starts_with($sort, 'flowering_')) {
            $column = $query->getQuery()->getGrammar()->wrap('strains.duration_overrides->flowering');
            $query->orderByRaw('COALESCE(CAST('.$column.' AS INTEGER), ?) '.$direction, [($this->settings()->durations['flowering'] ?? 0)]);
        } elseif (str_starts_with($sort, 'stock_')) {
            $stock = app(InventoryLocations::class)->restricted(auth()->user())
                ? InventoryBalance::selectRaw('COALESCE(SUM(quantity_units), 0)')->whereColumn('inventory_item_id', 'strains.seed_inventory_item_id')
                : InventoryItem::selectRaw('COALESCE(SUM(total_units), 0)')->whereColumn('inventory_items.id', 'strains.seed_inventory_item_id');
            $query->orderBy($stock, $direction);
        } elseif (str_starts_with($sort, 'yield_')) {
            $query->orderByRaw('theoretical_yield '.$direction.' NULLS LAST');
        }
        $query->orderBy('name_key', str_starts_with($sort, 'name_') ? $direction : 'asc')->orderBy('id');

        return $query->paginate(CollectionPagination::size($criteria), ['*'], 'page', $criteria['page'] ?? 1);
    }

    public function saveSettings(User $actor, array $data): LifecycleSetting
    {
        [$settings, $changed] = DB::transaction(function () use ($actor, $data) {
            $current = $this->lockActor($actor);
            Gate::forUser($current)->authorize('lifecycle.manage');
            $settings = LifecycleSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            if ($settings->version !== (int) $data['version']) {
                throw new CatalogException('catalog.errors.conflict');
            }
            $stages = $data['stages'] ?? $settings->stages;
            $durations = array_map(fn ($value) => (int) $value, $data['durations']);
            // Canonical key order keeps equivalent submissions idempotent.
            $keys = [...array_column($stages, 'id'), 'flush'];
            $durations = array_intersect_key(array_replace(array_fill_keys($keys, null), $durations), $durations);
            LifecycleDefinition::validate($stages, $durations);
            app(FertilizerSchedule::class)->validate($stages, $durations);
            app(PlantInventory::class)->validateRecipes($stages);
            $stages = app(FertilizerSchedule::class)->normalize($stages);
            $automatic = (bool) ($data['automatic_relocation'] ?? $settings->automatic_relocation);
            if ($automatic) {
                app(LifecycleRelocation::class)->validate($current, $stages);
            }
            $settings->automatic_relocation = $automatic;
            $settings->stages = $stages;
            $settings->durations = $durations;
            foreach (['watering_alert_days', 'watering_moisture_threshold'] as $key) {
                if (array_key_exists($key, $data)) {
                    $settings->$key = $data[$key];
                }
            }
            $changed = $settings->isDirty();
            if ($changed) {
                $settings->durations = $durations;
                $settings->version++;
                $settings->save();
            }

            return [$settings, $changed];
        });
        Log::notice('lifecycle_settings.updated', ['actor_uuid' => $actor->uuid, 'changed' => $changed]);

        request()->attributes->remove('lifecycle.stages');

        return $settings;
    }

    public function delete(User $actor, Strain $target, int $version): void
    {
        DB::transaction(function () use ($actor, $target, $version) {
            $current = $this->lockActor($actor);
            $record = Strain::whereKey($target->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($current)->authorize('delete', $record);
            if ($record->version !== $version) {
                throw new CatalogException('catalog.errors.conflict');
            }
            if (Plant::withoutGlobalScopes()->where('strain_id', $record->id)->exists()) {
                throw new CatalogException('catalog.errors.inUse');
            }
            $record->delete();
        });
        Log::notice('strain.deleted', ['actor_uuid' => $actor->uuid, 'strain_uuid' => $target->id]);
    }

    public function save(User $actor, array $data, ?Strain $target = null): Strain
    {
        try {
            [$record, $fields] = DB::transaction(function () use ($actor, $data, $target) {
                $current = $this->lockActor($actor);
                $record = $target ? Strain::whereKey($target->id)->lockForUpdate()->firstOrFail() : new Strain;
                Gate::forUser($current)->authorize($target ? 'update' : 'create', $target ? $record : Strain::class);
                if ($target && $record->version !== (int) $data['version']) {
                    throw new CatalogException('catalog.errors.conflict');
                }
                if (isset($data['seed_inventory_item_id'])) {
                    $stock = InventoryItem::whereKey($data['seed_inventory_item_id'])->lockForUpdate()->first();
                    if (! $stock || $stock->type !== 'seeds' || $stock->unit !== 'piece') {
                        throw ValidationException::withMessages(['seed_inventory_item_id' => ['catalog.validation.stock']]);
                    }
                }
                if (array_key_exists('duration_overrides', $data)) {
                    $normalized = [];
                    foreach (LifecycleDefinition::durationKeys() as $key) {
                        if (isset($data['duration_overrides'][$key])) {
                            $normalized[$key] = (int) $data['duration_overrides'][$key];
                        }
                    }
                    $data['duration_overrides'] = $normalized;
                }
                $photo = null;
                if (isset($data['photo_document_id'])) {
                    $photo = Document::whereKey($data['photo_document_id'])->lockForUpdate()->first();
                    if (! $photo || $photo->format !== 'image' || $photo->is_archived || ! Gate::forUser($current)->allows('view', $photo)) {
                        throw new CatalogException('catalog.errors.photoUnavailable', 422);
                    }
                }
                $record->fill(Arr::only($data, $record->getFillable()));
                $fields = array_values(array_intersect(array_keys($record->getDirty()), $record->getFillable()));
                if ($photo && (! $target || ! $record->photos()->whereKey($photo->id)->exists())) {
                    $fields[] = 'photo_document_id';
                }
                if ($target && $fields !== []) {
                    $record->version++;
                }
                $record->save();
                if ($photo) {
                    $record->photos()->syncWithoutDetaching([$photo->id]);
                }

                return [$record, $fields];
            });
        } catch (UniqueConstraintViolationException) {
            throw new CatalogException('catalog.errors.nameTaken', 422);
        }
        Log::notice($target ? 'strain.updated' : 'strain.created', ['actor_uuid' => $actor->uuid, 'strain_uuid' => $record->id, 'fields' => $fields]);

        return $record;
    }
}
