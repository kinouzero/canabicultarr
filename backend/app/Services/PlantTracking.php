<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Exceptions\PlantException;
use App\Models\Asset;
use App\Models\Document;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\LifecycleSetting;
use App\Models\Plant;
use App\Models\PlantEvent;
use App\Models\Room;
use App\Models\Sensor;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlantTracking
{
    public const SORT_COLUMNS = ['name' => 'name_key', 'created' => 'created_at', 'started' => 'started_at', 'due' => 'phase_due_at'];

    public function relations(): array
    {
        return [...Plant::locationRelations(), 'strain', 'devices', 'photoDocument', 'recentWaterings'];
    }

    public function search(array $criteria)
    {
        $query = Plant::with($this->relations());
        $status = $criteria['status'] ?? 'active';
        if ($status === 'active') {
            $query->whereNotIn('phase', ['planned', 'archived']);
        } elseif ($status !== 'all') {
            $query->where('phase', $status);
        }
        foreach (['phase', 'strain_id'] as $key) {
            if (filled($criteria[$key] ?? null)) {
                $query->where($key, $criteria[$key]);
            }
        }
        if (filled($criteria['location_id'] ?? null)) {
            $query->where($criteria['location_type'].'_id', $criteria['location_id']);
        }
        if (filled($criteria['site_id'] ?? null)) {
            $id = $criteria['site_id'];
            $query->where(fn ($q) => $q->where('site_id', $id)->orWhereHas('room', fn ($r) => $r->where('site_id', $id))->orWhereHas('zone.room', fn ($r) => $r->where('site_id', $id)));
        }
        if (filled($criteria['search'] ?? null)) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower(trim($criteria['search']))).'%';
            $reference = preg_match('/^#([0-9a-f-]{1,36})$/i', trim($criteria['search']), $match) ? '%'.Str::lower($match[1]).'%' : $pattern;
            $query->where(function ($names) use ($pattern, $reference) {
                $names->whereRaw("plants.name_key LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(CAST(plants.id AS VARCHAR)) LIKE ? ESCAPE '!'", [$reference])
                    ->orWhereHas('strain', fn ($strain) => $strain->whereRaw("strains.name_key LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhere(fn ($fallback) => $fallback->whereNull('plants.name')->whereRaw("(strains.name_key || ' · #' || SUBSTR(CAST(plants.id AS VARCHAR), 1, 8)) LIKE ? ESCAPE '!'", [$pattern])));
            });
        }

        return CollectionSorting::apply($query, $criteria['sort'] ?? null, self::SORT_COLUMNS)->paginate(CollectionPagination::size($criteria), ['*'], 'page', $criteria['page'] ?? 1);
    }

    private function actor(User $actor): User
    {
        $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        if ($current->session_version !== $actor->session_version) {
            throw new AuthenticationException;
        }

        return $current;
    }

    private function time(?string $value): CarbonImmutable
    {
        $time = $value ? CarbonImmutable::parse($value)->utc() : CarbonImmutable::now('UTC');
        if ($time->year < 2000 || $time->isFuture()) {
            throw new PlantException('plants.errors.timeInvalid', 422);
        }

        return $time;
    }

    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function locate(User $actor, Plant $plant, array $reference): void
    {
        $model = match ($reference['type']) {
            'site' => Site::class, 'room' => Room::class, 'zone' => Zone::class
        };
        $location = $model::whereKey($reference['id'])->firstOrFail();
        app(LocationAccess::class)->assertLocation($actor, ['type' => $reference['type'], 'id' => $location->id]);
        $plant->forceFill(['site_id' => null, 'room_id' => null, 'zone_id' => null, $reference['type'].'_id' => $location->id]);
        $plant->unsetRelation('site')->unsetRelation('room')->unsetRelation('zone');
    }

    private function devices(User $actor, array $ids): array
    {
        sort($ids);
        $devices = Asset::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        if ($devices->count() !== count($ids)) {
            throw new PlantException('plants.errors.deviceUnavailable', 422);
        }
        foreach ($devices as $device) {
            if ($device->status === AssetStatus::Retired || ! app(LocationAccess::class)->record($actor, $device)) {
                throw new PlantException('plants.errors.deviceUnavailable', 422);
            }
        }

        return $devices->pluck('id')->all();
    }

    private function phase(Plant $plant, string $phase, CarbonImmutable $time): void
    {
        $snapshot = $plant->duration_snapshot;
        $days = $snapshot['durations'][$phase] ?? $plant->strain->duration_overrides[$phase] ?? LifecycleSetting::findOrFail(1)->durations[$phase] ?? null;
        if ($days !== null && ! array_key_exists($phase, $snapshot['durations'])) {
            $snapshot['durations'][$phase] = $days;
            $plant->duration_snapshot = $snapshot;
        }
        $plant->phase = $phase;
        $plant->phase_started_at = $time;
        $plant->phase_due_at = $days === null ? null : $time->addDays($days);
    }

    private function event(Plant $plant, string $kind, ?string $from, CarbonImmutable $time, ?User $actor, array $extra = []): void
    {
        $event = $plant->events()->create([
            'plant_version' => $plant->version, 'kind' => $kind, 'from_phase' => $from, 'to_phase' => $plant->phase,
            'actor_uuid' => $actor?->uuid, 'occurred_at' => $time, 'recorded_at' => now(),
            'snapshot' => ['container' => $extra['container'] ?? null, 'substrates' => $extra['substrates'] ?? [], 'lifecycle_action' => LifecycleDefinition::action($kind), 'lifecycle_stage' => LifecycleDefinition::stage($plant->phase), 'harvest_weight_grams' => $plant->harvest_weight_grams, 'name' => $plant->displayName(), 'notes' => $plant->notes, 'strain_id' => $plant->strain_id, 'location' => $plant->locationDetails(), 'previous_location' => $extra['previous_location'] ?? null, 'automatic_relocation' => $extra['automatic_relocation'] ?? false, ...(isset($extra['strain_change']) ? ['strain_change' => $extra['strain_change']] : []), ...(isset($extra['watering']) ? ['watering' => $extra['watering']] : []), ...(isset($extra['maintenance']) ? ['maintenance' => $extra['maintenance']] : [])],
            'fields' => $extra['fields'] ?? [], 'request_id' => $extra['request_id'] ?? null,
            'request_hash' => $extra['request_hash'] ?? null, 'comment' => $extra['comment'] ?? null,
        ]);
        if (! empty($extra['document_ids'])) {
            $event->documents()->attach($extra['document_ids']);
        }
    }

    private function attachmentHash(array $data): array
    {
        $ids = $data['document_ids'] ?? [];
        sort($ids);

        return $ids === [] ? [] : ['document_ids' => $ids];
    }

    private function attachments(User $actor, array $data): array
    {
        $ids = $data['document_ids'] ?? [];
        sort($ids);
        if (count($ids) > 10 || count(array_unique($ids)) !== count($ids)) {
            throw new PlantException('plants.errors.attachmentUnavailable', 422);
        }
        $documents = Document::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        if ($documents->count() !== count($ids)) {
            throw new PlantException('plants.errors.attachmentUnavailable', 422);
        }
        foreach ($documents as $document) {
            if ($document->is_archived || ! Gate::forUser($actor)->allows('view', $document)) {
                throw new PlantException('plants.errors.attachmentUnavailable', 422);
            }
        }

        return $documents->pluck('id')->all();
    }

    private function summarySensors(?array $ids, array $deviceIds, bool $trim = false): ?array
    {
        if ($ids === null) {
            return null;
        }
        sort($ids);
        $visible = Sensor::whereIn('asset_id', $deviceIds)->whereIn('id', $ids)->orderBy('id')->pluck('id')->all();
        if (! $trim && $visible !== $ids) {
            throw new PlantException('plants.errors.channelUnavailable', 422);
        }

        return $visible;
    }

    public function create(User $actor, array $data): Plant
    {
        $ids = $data['device_ids'] ?? [];
        sort($ids);
        $input = ['actor' => $actor->uuid, 'name' => Plant::normalizeName($data['name'] ?? null), 'notes' => $data['notes'] ?? null, 'strain' => $data['strain_id'], 'location' => ['type' => $data['location']['type'], 'id' => $data['location']['id']], 'devices' => $ids, 'started_at' => isset($data['started_at']) ? CarbonImmutable::parse($data['started_at'])->utc()->toISOString() : null];
        if ($data['planned'] ?? false) {
            $input['planned'] = true;
        }
        $summary = $data['summary_sensor_ids'] ?? null;
        if ($summary !== null) {
            sort($summary);
        }
        $input['summary_sensor_ids'] = $summary;
        $hash = $this->hash($input);
        try {
            $plant = DB::transaction(function () use ($actor, $data, $input, $hash, $ids) {
                $current = $this->actor($actor);
                Gate::forUser($current)->authorize('create', Plant::class);
                $existing = Plant::where('creation_key', $data['request_id'])->first();
                if ($existing) {
                    if (! hash_equals($existing->creation_hash, $hash)) {
                        throw new PlantException('plants.errors.requestConflict');
                    }

                    return $existing;
                }
                $settings = LifecycleSetting::whereKey(1)->lockForUpdate()->firstOrFail();
                $strain = Strain::whereKey($data['strain_id'])->lockForUpdate()->first();
                if (! $strain || $strain->is_archived) {
                    throw new PlantException('plants.errors.strainUnavailable', 422);
                }
                if ($strain->seed_inventory_item_id) {
                    $stock = InventoryItem::whereKey($strain->seed_inventory_item_id)->lockForUpdate()->first();
                    $quantity = $stock?->total_units ?? 0;
                    if ($stock && app(InventoryLocations::class)->restricted($current)) {
                        $balances = InventoryBalance::withoutGlobalScopes()->where('inventory_item_id', $stock->id);
                        app(InventoryLocations::class)->scope($balances, $current);
                        $quantity = (int) $balances->sum('quantity_units');
                    }
                    if ($quantity <= 0) {
                        throw new PlantException('plants.errors.seedStockEmpty', 422);
                    }
                }
                $time = $this->time($data['started_at'] ?? null);
                $plant = new Plant(['creation_key' => $data['request_id'], 'creation_hash' => $hash, 'name' => $input['name'], 'notes' => $input['notes'], 'strain_id' => $strain->id, 'started_at' => ($data['planned'] ?? false) ? null : $time,
                    'duration_snapshot' => ['durations' => array_replace($settings->durations, $strain->duration_overrides ?? []), 'settings_version' => $settings->version, 'strain_version' => $strain->version]]);
                $this->locate($current, $plant, $data['location']);
                $deviceIds = $this->devices($current, $ids);
                $plant->summary_sensor_ids = $this->summarySensors($input['summary_sensor_ids'], $deviceIds);
                if ($data['planned'] ?? false) {
                    $plant->phase = 'planned';
                } else {
                    $this->phase($plant, $settings->stages[1]['id'], $time);
                }
                $previousLocation = app(LifecycleRelocation::class)->apply($current, $plant, $settings);
                $plant->save();
                $plant->devices()->sync($deviceIds);
                $this->event($plant, 'created', null, $time, $current, ['previous_location' => $previousLocation, 'automatic_relocation' => $previousLocation !== null]);

                return $plant;
            });
        } catch (UniqueConstraintViolationException) {
            throw new PlantException('plants.errors.requestConflict');
        }
        Log::notice('plant.created', ['actor_uuid' => $actor->uuid, 'plant_uuid' => $plant->id]);

        return $plant->load($this->relations());
    }

    public function delete(User $actor, Plant $target, int $version): void
    {
        DB::transaction(function () use ($actor, $target, $version) {
            $current = $this->actor($actor);
            $plant = Plant::whereKey($target->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($current)->authorize('delete', $plant);
            $this->version($plant, $version);
            app(AssetContexts::class)->lockForPlant($plant);
            $events = DB::table('plant_events')->where('plant_id', $plant->id)->select('id');
            DB::table('plant_event_documents')->whereIn('plant_event_id', $events)->delete();
            DB::table('plant_events')->where('plant_id', $plant->id)->delete();
            $plant->devices()->detach();
            $plant->delete();
        });
        Log::notice('plant.deleted', ['actor_uuid' => $actor->uuid, 'plant_uuid' => $target->id]);
    }

    public function update(User $actor, Plant $target, array $data): Plant
    {
        [$plant, $fields] = DB::transaction(function () use ($actor, $target, $data) {
            $current = $this->actor($actor);
            $plant = Plant::whereKey($target->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($current)->authorize('update', $plant);
            $this->version($plant, $data['version']);
            if ($plant->phase === 'archived') {
                throw new PlantException('plants.errors.archived');
            }
            $before = $plant->locationDetails();
            $strainChange = null;
            if (isset($data['strain_id']) && $data['strain_id'] !== $plant->strain_id) {
                $reason = $data['strain_change_reason'] ?? null;
                if (! is_string($reason) || ! preg_match('/[^\s\p{Z}]/u', $reason) || mb_strlen($reason) > 2000) {
                    throw ValidationException::withMessages(['strain_change_reason' => 'plants.validation.strainChangeReason']);
                }
                $replacement = Strain::whereKey($data['strain_id'])->lockForUpdate()->first();
                if (! $replacement || $replacement->is_archived) {
                    throw new PlantException('plants.errors.strainUnavailable', 422);
                }
                $strainChange = [
                    'previous' => ['id' => $plant->strain_id, 'name' => $plant->strain->name],
                    'current' => ['id' => $replacement->id, 'name' => $replacement->name],
                    'reason' => preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $reason),
                ];
                $plant->strain_id = $replacement->id;
                $plant->setRelation('strain', $replacement);
            }
            foreach (['name', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $plant->$field = $field === 'name' ? Plant::normalizeName($data[$field] ?? null) : $data[$field];
                }
            }
            if (isset($data['location'])) {
                $this->locate($current, $plant, $data['location']);
            }
            if (array_key_exists('photo_document_id', $data)) {
                $document = $data['photo_document_id'] ? Document::whereKey($data['photo_document_id'])->lockForUpdate()->first() : null;
                if ($data['photo_document_id'] && (! $document || $document->format !== 'image' || $document->is_archived || ! Gate::forUser($current)->allows('view', $document))) {
                    throw new PlantException('plants.errors.photoUnavailable', 422);
                }
                if ($plant->photo_document_id) {
                    $plant->photos()->syncWithoutDetaching([$plant->photo_document_id]);
                }
                if ($document) {
                    $plant->photos()->syncWithoutDetaching([$document->id]);
                }
                $plant->photo_document_id = $document?->id;
            }
            $fields = array_values(array_intersect(array_keys($plant->getDirty()), ['name', 'notes', 'strain_id', 'site_id', 'room_id', 'zone_id', 'photo_document_id']));
            app(AssetContexts::class)->lockForPlant($plant, $data['device_ids'] ?? []);
            if (array_key_exists('device_ids', $data)) {
                $ids = $this->devices($current, $data['device_ids']);
                $oldIds = $plant->devices()->withoutGlobalScopes()->orderBy('assets.id')->pluck('assets.id')->all();
                $visibleQuery = Asset::withoutGlobalScopes()->whereKey($oldIds);
                app(LocationAccess::class)->asset($visibleQuery, 'assets', $current);
                $visible = $visibleQuery->pluck('id')->all();
                // The editor cannot remove a shared association it was not allowed to see.
                $ids = array_values(array_unique([...$ids, ...array_diff($oldIds, $visible)]));
                sort($ids);
                if ($ids !== $oldIds) {
                    $plant->devices()->sync($ids);
                    $fields[] = 'devices';
                }
            }
            if (array_key_exists('summary_sensor_ids', $data) || in_array('devices', $fields, true)) {
                $deviceIds = $plant->devices()->pluck('assets.id')->all();
                $explicit = array_key_exists('summary_sensor_ids', $data);
                $plant->summary_sensor_ids = $this->summarySensors($explicit ? $data['summary_sensor_ids'] : $plant->summary_sensor_ids, $deviceIds, ! $explicit);
                if ($plant->isDirty('summary_sensor_ids')) {
                    $fields[] = 'summary_sensor_ids';
                }
            }
            if ($fields !== []) {
                $plant->version++;
                $plant->save();
                $this->event($plant, 'updated', $plant->phase, CarbonImmutable::now('UTC'), $current, ['fields' => $fields, 'previous_location' => $before, 'strain_change' => $strainChange]);
            }

            return [$plant, $fields];
        });
        Log::notice('plant.updated', ['actor_uuid' => $actor->uuid, 'plant_uuid' => $plant->id, 'fields' => $fields]);

        return $plant->load($this->relations());
    }

    private function version(Plant $plant, int $version): void
    {
        if ($plant->version !== $version) {
            throw new PlantException('plants.errors.conflict');
        }
    }

    public function action(User $actor, Plant $target, array $data): Plant
    {
        $weight = null;
        if (array_key_exists('harvest_weight_grams', $data)) {
            $value = $data['harvest_weight_grams'] ?? null;
            if (! is_numeric($value) || ! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', (string) $value) || (float) $value <= 0) {
                throw new PlantException('plants.validation.harvestWeight', 422);
            }
            $weight = number_format((float) $value, 2, '.', '');
        }
        $substrates = $data['substrates'] ?? [];
        usort($substrates, fn ($a, $b) => strcmp($a['inventory_item_id'], $b['inventory_item_id']));
        $hash = $this->hash(['container' => $data['container'] ?? null, 'substrates' => $substrates, 'action' => $data['action'], 'comment' => $data['comment'] ?? null, 'occurred_at' => isset($data['occurred_at']) ? CarbonImmutable::parse($data['occurred_at'])->utc()->toISOString() : null, ...$this->attachmentHash($data), ...($weight === null ? [] : ['harvest_weight_grams' => $weight])]);
        $plant = DB::transaction(function () use ($actor, $target, $data, $hash, $weight) {
            $current = $this->actor($actor);
            $plant = Plant::whereKey($target->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($current)->authorize('update', $plant);
            $existing = PlantEvent::withoutGlobalScopes()->where('plant_id', $plant->id)->where('request_id', $data['request_id'])->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash) || $existing->actor_uuid !== $current->uuid) {
                    throw new PlantException('plants.errors.requestConflict');
                }

                return $plant;
            }
            $this->version($plant, $data['version']);
            $data['document_ids'] = $this->attachments($current, $data);
            $definition = LifecycleSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            $stage = collect($definition->stages)->firstWhere('id', $plant->phase);
            $action = collect($stage['actions'] ?? [])->firstWhere('id', $data['action']);
            if (! $action) {
                throw new PlantException('plants.errors.actionUnavailable');
            }
            if ((bool) $action['requires_harvest_weight'] !== ($weight !== null)) {
                throw new PlantException('plants.validation.harvestWeight', 422);
            }
            $used = app(PlantInventory::class)->consume($current, $plant, $data, false);
            $container = app(PlantInventory::class)->changeContainer($current, $plant, $data);
            $from = $plant->phase;
            $to = $action['target'];
            $time = $this->time($data['occurred_at'] ?? null);
            if (($earliest = $plant->phase_started_at ?? $plant->started_at) && $time->lessThan($earliest)) {
                throw new PlantException('plants.errors.timeInvalid', 422);
            }
            if ($from === 'planned' && $to !== 'planned') {
                $plant->started_at ??= $time;
            }
            if ($weight !== null) {
                $plant->harvest_weight_grams = $weight;
            }
            $previousLocation = null;
            if ($from !== $to) {
                $this->phase($plant, $to, $time);
                $previousLocation = app(LifecycleRelocation::class)->apply($current, $plant, $definition);
            }
            $plant->version++;
            $plant->save();
            $this->event($plant, $data['action'], $from, $time, $current, [...$data, 'request_hash' => $hash, 'substrates' => $used, 'container' => $container, 'previous_location' => $previousLocation, 'automatic_relocation' => $previousLocation !== null, 'fields' => $previousLocation ? ['location'] : []]);

            return $plant;
        });
        Log::notice('plant.action_recorded', ['actor_uuid' => $actor->uuid, 'plant_uuid' => $plant->id, 'action' => $data['action']]);

        return $plant->load($this->relations());
    }

    public function water(User $actor, Plant $target, array $data, bool $offline = false): Plant
    {
        $fertilizers = $data['fertilizers'] ?? [];
        usort($fertilizers, fn ($a, $b) => strcmp($a['inventory_item_id'], $b['inventory_item_id']));
        $hash = $this->hash(['water_liters' => $data['water_liters'] ?? null, 'fertilizers' => $fertilizers, 'action' => 'watered', 'with_products' => (bool) $data['with_products'], 'comment' => $data['comment'] ?? null, 'occurred_at' => isset($data['occurred_at']) ? CarbonImmutable::parse($data['occurred_at'])->utc()->toISOString() : null, ...$this->attachmentHash($data)]);
        $plant = DB::transaction(function () use ($actor, $target, $data, $hash, $offline) {
            $current = $this->actor($actor);
            $plant = Plant::whereKey($target->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($current)->authorize('update', $plant);
            $existing = PlantEvent::withoutGlobalScopes()->where('plant_id', $plant->id)->where('request_id', $data['request_id'])->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash) || $existing->actor_uuid !== $current->uuid) {
                    throw new PlantException('plants.errors.requestConflict');
                }

                return $plant;
            }
            $pump = $offline ? null : app(PumpControl::class)->watering($current, $plant, $data['request_id']);
            $this->version($plant, $data['version']);
            $data['document_ids'] = $this->attachments($current, $data);
            if (! in_array($plant->phase, LifecycleDefinition::allowing('watering'), true)) {
                throw new PlantException('plants.errors.wateringUnavailable');
            }
            $time = $this->time($data['occurred_at'] ?? null);
            if (($earliest = $plant->phase_started_at ?? $plant->started_at) && $time->lessThan($earliest)) {
                throw new PlantException('plants.errors.timeInvalid', 422);
            }
            if ($offline && $data['with_products']) {
                $scheduled = array_column(app(FertilizerSchedule::class)->forPlant($plant, $time)['products'], 'inventory_item_id');
                $selected = array_column($data['fertilizers'], 'inventory_item_id');
                if (PlantWatering::duringFlush($plant, $time) || array_diff($selected, $scheduled) !== []) {
                    throw new PlantException('offlinePlants.recipeChanged');
                }
            }
            $plant->version++;
            $plant->save();
            $used = app(PlantInventory::class)->consume($current, $plant, $data);
            $this->event($plant, 'watered', $plant->phase, $time, $current, [...$data, 'request_hash' => $hash, 'watering' => ['pump' => $pump, 'with_products' => (bool) $data['with_products'], 'during_flush' => PlantWatering::duringFlush($plant, $time), 'water_liters' => $data['water_liters'] ?? null, 'fertilizers' => $used]]);

            return $plant;
        });
        Log::notice('plant.watering_recorded', ['actor_uuid' => $actor->uuid, 'plant_uuid' => $plant->id]);

        return $plant->load($this->relations());
    }

    public function maintenanceTypes(): array
    {
        return app(PlantMaintenanceTypes::class)->records()->pluck('name')->all();
    }

    public function maintain(User $actor, Plant $target, array $data): Plant
    {
        $hash = $this->hash(['action' => 'maintained', 'type' => $data['type'], 'comment' => $data['comment'] ?? null,
            'occurred_at' => isset($data['occurred_at']) ? CarbonImmutable::parse($data['occurred_at'])->utc()->toISOString() : null,
            ...$this->attachmentHash($data)]);
        $plant = DB::transaction(function () use ($actor, $target, $data, $hash) {
            $current = $this->actor($actor);
            $plant = Plant::whereKey($target->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($current)->authorize('update', $plant);
            $existing = PlantEvent::withoutGlobalScopes()->where('plant_id', $plant->id)->where('request_id', $data['request_id'])->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash) || $existing->actor_uuid !== $current->uuid) {
                    throw new PlantException('plants.errors.requestConflict');
                }

                return $plant;
            }
            $this->version($plant, $data['version']);
            if (! in_array($plant->phase, LifecycleDefinition::allowing('maintenance'), true)) {
                throw new PlantException('plants.errors.maintenanceUnavailable');
            }
            $data['document_ids'] = $this->attachments($current, $data);
            $time = $this->time($data['occurred_at'] ?? null);
            if (($earliest = $plant->phase_started_at ?? $plant->started_at) && $time->lessThan($earliest)) {
                throw new PlantException('plants.errors.timeInvalid', 422);
            }
            $plant->version++;
            $plant->save();
            app(PlantMaintenanceTypes::class)->remember($data['type']);
            $this->event($plant, 'maintained', $plant->phase, $time, $current, [...$data, 'request_hash' => $hash, 'maintenance' => ['type' => $data['type']]]);

            return $plant;
        });
        Log::notice('plant.maintenance_recorded', ['actor_uuid' => $actor->uuid, 'plant_uuid' => $plant->id]);

        return $plant->load($this->relations());
    }
}
