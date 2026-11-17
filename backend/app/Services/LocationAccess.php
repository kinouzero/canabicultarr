<?php

namespace App\Services;

use App\Models\AlertEpisode;
use App\Models\AlertRule;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetLocation;
use App\Models\Document;
use App\Models\EventEntry;
use App\Models\EventRevision;
use App\Models\HttpMeasurementSource;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Measurement;
use App\Models\MqttMeasurementSource;
use App\Models\Plant;
use App\Models\PlantEvent;
use App\Models\RemoteSensorLink;
use App\Models\Room;
use App\Models\Sensor;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LocationAccess
{
    private array $cache = [];

    private array $decisions = [];

    private ?User $override = null;

    public function actor(): ?User
    {
        return $this->override ?? Auth::user();
    }

    public function run(User $user, \Closure $work): mixed
    {
        $previous = $this->override;
        $this->override = $user;
        try {
            return $work();
        } finally {
            $this->override = $previous;
        }
    }

    public function clear(): void
    {
        $this->cache = [];
        $this->decisions = [];
    }

    public function restricted(?User $user = null): bool
    {
        $user ??= $this->actor();

        return $user && ! $user->isAdministrator() && $user->location_access !== null;
    }

    public function normalize(?array $entries): ?array
    {
        if ($entries === null) {
            return null;
        }
        $result = [];
        foreach ($entries as $entry) {
            $type = $entry['type'] ?? '';
            $id = strtolower($entry['id'] ?? '');
            $model = match ($type) {
                'site' => Site::class, 'room' => Room::class, 'zone' => Zone::class, default => null
            };
            if (! $model || ! $model::withoutGlobalScopes()->whereKey($id)->exists()) {
                throw ValidationException::withMessages(['location_access' => ['locationAccess.errors.invalidSelection']]);
            }
            $result[$type.':'.$id] = ['type' => $type, 'id' => $id];
        }
        ksort($result);

        return array_values($result);
    }

    public function describe(User $user): ?array
    {
        if (! $this->restricted($user)) {
            return null;
        }
        $result = [];
        foreach ($user->location_access ?? [] as $entry) {
            $table = match ($entry['type']) {
                'site' => 'sites', 'room' => 'rooms', 'zone' => 'zones'
            };
            $result[] = [...$entry, 'name' => DB::table($table)->where('id', $entry['id'])->value('name') ?? ''];
        }

        return $result;
    }

    private function sets(User $user): array
    {
        $key = $user->id.':'.$user->session_version.':'.json_encode($user->location_access);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $entries = $user->location_access ?? [];
        $sites = array_column(array_filter($entries, fn ($e) => $e['type'] === 'site'), 'id');
        $rooms = array_column(array_filter($entries, fn ($e) => $e['type'] === 'room'), 'id');
        $zones = array_column(array_filter($entries, fn ($e) => $e['type'] === 'zone'), 'id');
        $direct = ['site' => $sites, 'room' => $rooms, 'zone' => $zones];
        // Keep inheritance inside SQL: bindings depend on explicit grants, not descendants.
        $rooms = DB::table('rooms')->select('id')->whereIn('id', $direct['room'])->orWhereIn('site_id', $sites);
        $zones = DB::table('zones')->leftJoin('rooms as access_rooms', 'access_rooms.id', '=', 'zones.room_id')
            ->select('zones.id')->whereIn('zones.id', $direct['zone'])
            ->orWhereIn('zones.room_id', $direct['room'])->orWhereIn('access_rooms.site_id', $sites);
        // Navigation adds ancestors of direct grants, not ancestors of another inherited query.
        $navRooms = DB::table('rooms')->select('id')->whereIn('id', $direct['room'])->orWhereIn('site_id', $sites)
            ->orWhereIn('id', DB::table('zones')->select('room_id')->whereIn('id', $direct['zone']));
        $navSites = DB::table('sites')->select('id')->whereIn('id', $sites)
            ->orWhereIn('id', DB::table('rooms')->select('site_id')->whereIn('id', $direct['room']))
            ->orWhereIn('id', DB::table('zones')->join('rooms', 'rooms.id', '=', 'zones.room_id')
                ->select('rooms.site_id')->whereIn('zones.id', $direct['zone']));

        return $this->cache[$key] = ['site' => $sites, 'room' => $rooms, 'zone' => $zones, 'nav_site' => $navSites, 'nav_room' => $navRooms, 'direct' => $direct];
    }

    public function allows(?User $user, ?array $location): bool
    {
        if (! $user || ! $user->is_active) {
            return false;
        }
        if (! $this->restricted($user)) {
            return true;
        }
        if (! $location) {
            return false;
        }
        $reference = $this->reference($location);
        if (! $reference) {
            return false;
        }
        $this->prepareLocations($user, [$location]);

        return $this->decisions[$this->decisionKey($user)][$reference[0]][$reference[1]];
    }

    private function reference(?array $location): ?array
    {
        if (! $location) {
            return null;
        }
        $type = $location['type'] ?? (isset($location['zone']['id']) ? 'zone' : (isset($location['room']['id']) ? 'room' : 'site'));
        if (! in_array($type, ['site', 'room', 'zone'], true)) {
            return null;
        }
        $id = $location['id'] ?? $location[$type]['id'] ?? null;

        return is_string($id) && Str::isUuid($id) ? [$type, $id] : null;
    }

    private function decisionKey(User $user): string
    {
        return $user->id.':'.$user->session_version.':'.json_encode($user->location_access);
    }

    private function prepareLocations(User $user, array $locations): void
    {
        $key = $this->decisionKey($user);
        $sets = $this->sets($user);
        $pending = [];
        foreach ($locations as $location) {
            $reference = $this->reference($location);
            if (! $reference) {
                continue;
            }
            [$type, $id] = $reference;
            if (isset($this->decisions[$key][$type][$id])) {
                continue;
            }
            $direct = in_array($id, $sets['direct'][$type], true);
            $this->decisions[$key][$type][$id] = $direct;
            if (! $direct && $type !== 'site') {
                $pending[$type][$id] = true;
            }
        }
        foreach ($pending as $type => $ids) {
            // Restrict SQL to the consulted IDs, never materialize the permitted subtree.
            foreach (array_chunk(array_keys($ids), 250) as $batch) {
                $allowed = DB::query()->fromSub($sets[$type], 'permitted_locations')->select('id')->whereIn('id', $batch)->pluck('id');
                foreach ($allowed as $id) {
                    $this->decisions[$key][$type][$id] = true;
                }
            }
        }
    }

    public function prepare(?User $user, iterable $records): void
    {
        if (! $user || ! $user->is_active || ! $this->restricted($user)) {
            return;
        }
        $pending = [...$records];
        $seen = new \SplObjectStorage;
        $locations = [];
        $assets = [];
        $contexts = [];
        while ($pending) {
            $record = array_pop($pending);
            if (! is_object($record) || $seen->contains($record)) {
                continue;
            }
            $seen->attach($record);
            if ($record instanceof Asset) {
                $assets[] = $record->id;
            }
            if ($record instanceof AssetAssignment && $record->asset_context_id) {
                $contexts[] = $record->asset_context_id;
            }
            if ($record instanceof AlertEpisode && isset($record->rule_snapshot['assetContextId'])) {
                $contexts[] = $record->rule_snapshot['assetContextId'];
            }
            if (($record instanceof Document || $record instanceof EventEntry) && isset($record->subject_snapshot['assetContextId'])) {
                $contexts[] = $record->subject_snapshot['assetContextId'];
            }
            if ($record instanceof Location) {
                $locations[] = ['type' => $record->kind(), 'id' => $record->id];
            } elseif ($record instanceof Plant || $record instanceof AssetLocation || $record instanceof AssetAssignment) {
                foreach (['zone', 'room', 'site'] as $type) {
                    $id = $record->getAttribute($type.'_id');
                    if ($id) {
                        $locations[] = ['type' => $type, 'id' => $id];
                        break;
                    }
                }
            } elseif ($record instanceof AlertEpisode) {
                $locations[] = $record->rule_snapshot['location'] ?? null;
            } elseif ($record instanceof Document || $record instanceof EventEntry) {
                $locations[] = $record->subject_snapshot['location'] ?? null;
            } elseif ($record instanceof \stdClass && isset($record->kind, $record->id)) {
                // Location catalog rows include selectable ancestors as well as their own node.
                $locations[] = ['type' => $record->kind, 'id' => $record->id];
                foreach (['site', 'room'] as $type) {
                    if ($record->{$type.'_id'} ?? null) {
                        $locations[] = ['type' => $type, 'id' => $record->{$type.'_id'}];
                    }
                }
            }
            if ($record instanceof Model) {
                // Only traverse already eager-loaded relations; preparing decisions must not load more models.
                foreach ($record->getRelations() as $relation) {
                    if ($relation instanceof Model) {
                        $pending[] = $relation;
                    } elseif ($relation instanceof Collection) {
                        foreach ($relation as $child) {
                            $pending[] = $child;
                        }
                    }
                }
            }
        }
        $this->prepareLocations($user, $locations);
        foreach (array_chunk(array_unique($assets), 250) as $batch) {
            $query = Asset::withoutGlobalScopes()->whereKey($batch);
            $this->asset($query, 'assets', $user);
            $allowed = array_fill_keys($query->pluck('id')->all(), true);
            foreach ($batch as $id) {
                $this->cache[$this->decisionKey($user).':asset:'.$id] = isset($allowed[$id]);
            }
        }
        foreach (array_chunk(array_unique($contexts), 250) as $batch) {
            $allowed = array_fill_keys($this->allowedContexts($user)->whereIn('asset_contexts.id', $batch)->pluck('id')->all(), true);
            foreach ($batch as $id) {
                $this->cache[$this->decisionKey($user).':context:'.$id] = isset($allowed[$id]);
            }
        }
    }

    public function assertLocation(User $user, ?array $location): void
    {
        if (! $this->allows($user, $location)) {
            throw new AuthorizationException;
        }
    }

    public function assertGlobal(?User $user = null): void
    {
        if ($this->restricted($user)) {
            throw new AuthorizationException;
        }
    }

    private function contextAllowed(User $actor, int $id): bool
    {
        $key = $this->decisionKey($actor).':context:'.$id;

        return $this->cache[$key] ??= $this->allowedContexts($actor)->where('asset_contexts.id', $id)->exists();
    }

    public function allowsSubject(User $actor, ?array $subject): bool
    {
        if (! $actor->is_active) {
            return false;
        }
        if (isset($subject['assetContextId'])) {
            return ! $this->restricted($actor) ? $actor->is_active : $this->contextAllowed($actor, (int) $subject['assetContextId']);
        }

        return $this->allows($actor, $subject['location'] ?? null);
    }

    public function assertSubject(User $actor, ?array $subject): void
    {
        if (! $this->allowsSubject($actor, $subject)) {
            throw new AuthorizationException;
        }
    }

    private function subject($query, string $table, User $actor): void
    {
        $query->where(function ($q) use ($table, $actor) {
            $q->where(function ($single) use ($table, $actor) {
                $single->whereNull($table.'.subject_snapshot->assetContextId');
                $this->locationColumns($single, $table, $actor);
            })->orWhereIn(DB::raw('CAST('.DB::connection()->getQueryGrammar()->wrap($table.'.subject_snapshot->assetContextId').' AS VARCHAR)'), $this->allowedContexts($actor)->select([])->selectRaw('CAST(asset_contexts.id AS VARCHAR)'));
        });
    }

    public function assertRecord(User $actor, $record): void
    {
        if (! $this->record($actor, $record)) {
            throw new AuthorizationException;
        }
    }

    public function asset($query, string $table, User $user): void
    {
        if (! $this->restricted($user)) {
            return;
        }
        // Correlate each boundary independently, including hardware linked only to plants.
        $query->whereExists(fn ($any) => $any->selectRaw('1')->from('asset_boundaries')->whereColumn('asset_id', $table.'.id'))
            ->whereNotExists(function ($outside) use ($table, $user) {
                $outside->selectRaw('1')->from('asset_boundaries')->whereColumn('asset_id', $table.'.id');
                $permitted = DB::table('asset_boundaries as permitted')->select('permitted.boundary_id')->whereColumn('permitted.asset_id', $table.'.id');
                $this->locationColumns($permitted, 'permitted', $user);
                $outside->whereNotIn('asset_boundaries.boundary_id', $permitted);
            });
    }

    public function record(User $user, $record): bool
    {
        if (! $user->is_active) {
            return false;
        }
        if ($record instanceof InventoryBalance) {
            return app(InventoryLocations::class)->allows($user, $record->storage_location_id);
        }
        if (! $this->restricted($user)) {
            return $user->is_active;
        }
        if ($record instanceof Location) {
            return $this->allows($user, ['type' => $record->kind(), 'id' => $record->id]);
        }
        if ($record instanceof Asset) {
            $key = $this->decisionKey($user).':asset:'.$record->id;
            if (array_key_exists($key, $this->cache)) {
                return $this->cache[$key];
            }
            $query = Asset::withoutGlobalScopes()->whereKey($record->id);
            $this->asset($query, 'assets', $user);

            return $this->cache[$key] = $query->exists();
        }
        if ($record instanceof AssetAssignment && $record->locations_snapshot !== null) {
            return $this->contextAllowed($user, (int) $record->asset_context_id);
        }
        if ($record instanceof Plant || $record instanceof AssetLocation || $record instanceof AssetAssignment) {
            return $this->allows($user, $record->locationDetails());
        }
        if ($record instanceof Sensor) {
            return $this->record($user, $record->device()->withoutGlobalScopes()->first());
        }
        if ($record instanceof AlertRule || $record instanceof RemoteSensorLink || $record instanceof HttpMeasurementSource || $record instanceof MqttMeasurementSource) {
            return $this->record($user, $record->sensor()->withoutGlobalScopes()->first());
        }
        if ($record instanceof AlertEpisode) {
            if (isset($record->rule_snapshot['provenanceStatus'])) {
                return $this->contextAllowed($user, (int) ($record->rule_snapshot['assetContextId'] ?? 0));
            }

            return $this->allows($user, $record->rule_snapshot['location'] ?? null);
        }
        if ($record instanceof Document && $record->subject_kind === 'strain') {
            return $user->is_active && Strain::whereKey($record->subject_snapshot['id'] ?? null)->exists();
        }
        if ($record instanceof Document || $record instanceof EventEntry) {
            return $this->allowsSubject($user, $record->subject_snapshot);
        }

        return false;
    }

    public function snapshot($query, string $column, ?User $user = null, bool $nullable = false): void
    {
        $user ??= $this->actor();
        if (! $this->restricted($user)) {
            return;
        }
        $sets = $this->sets($user);
        $query->where(function ($q) use ($column, $sets, $nullable) {
            if ($nullable) {
                $q->whereNull($column);
            } else {
                $q->whereRaw('1=0');
            }
            foreach (['site', 'room', 'zone'] as $type) {
                $selection = $sets[$type];
                // JSON location IDs are text, while PostgreSQL location keys are UUIDs.
                if (! is_array($selection)) {
                    // Explicit grants also cover historical snapshots after a node is deleted.
                    $q->orWhereIn($column.'->'.$type.'->id', $sets['direct'][$type]);
                    $selection = DB::query()->fromSub($selection, 'permitted_locations')->selectRaw('CAST(id AS VARCHAR)');
                }
                $q->orWhereIn($column.'->'.$type.'->id', $selection);
            }
        });
    }

    public function measurement($query, string $table = 'measurements', ?User $user = null): void
    {
        $user ??= $this->actor();
        if (! $this->restricted($user)) {
            return;
        }
        $query->where(function ($q) use ($table, $user) {
            $q->where(function ($legacy) use ($table, $user) {
                $legacy->whereNull($table.'.provenance_snapshot');
                $this->snapshot($legacy, $table.'.location_snapshot', $user);
            })->orWhereIn($table.'.asset_context_id', $this->allowedContexts($user));
        });
    }

    private function allowedContexts(User $user)
    {
        $allowed = DB::table('asset_context_locations')->select('id');
        $this->locationColumns($allowed, 'asset_context_locations', $user, true);

        return DB::table('asset_contexts')->select('asset_contexts.id')
            ->whereExists(fn ($contexts) => $contexts->selectRaw('1')->from('asset_context_locations')->whereColumn('asset_context_id', 'asset_contexts.id'))
            ->whereNotExists(fn ($outside) => $outside->selectRaw('1')->from('asset_context_locations')->whereColumn('asset_context_id', 'asset_contexts.id')->whereNotIn('id', $allowed));
    }

    private function episode($query, string $table, User $user): void
    {
        $query->where(function ($q) use ($table, $user) {
            $q->where(function ($legacy) use ($table, $user) {
                $legacy->whereNull($table.'.rule_snapshot->provenanceStatus');
                $this->snapshot($legacy, $table.'.rule_snapshot->location', $user);
            })->orWhereIn(DB::raw('CAST('.DB::connection()->getQueryGrammar()->wrap($table.'.rule_snapshot->assetContextId').' AS VARCHAR)'), $this->allowedContexts($user)->select([])->selectRaw('CAST(asset_contexts.id AS VARCHAR)'));
        });
    }

    public function locationColumns($query, string $table, ?User $user = null, bool $historical = false): void
    {
        $user ??= $this->actor();
        if (! $this->restricted($user)) {
            return;
        }
        $sets = $this->sets($user);
        // Assets store exactly one location FK; captured contexts can store all ancestors.
        // Match the deepest captured location, never an ancestor-only navigation grant.
        $query->where(function ($q) use ($sets, $table, $historical) {
            $q->whereIn($table.'.zone_id', $sets['zone'])
                ->orWhere(fn ($r) => $r->whereNull($table.'.zone_id')->whereIn($table.'.room_id', $sets['room']))
                ->orWhere(fn ($r) => $r->whereNull($table.'.zone_id')->whereNull($table.'.room_id')->whereIn($table.'.site_id', $sets['site']));
            if ($historical) {
                $q->orWhereIn($table.'.zone_id', $sets['direct']['zone'])
                    ->orWhere(fn ($r) => $r->whereNull($table.'.zone_id')->whereIn($table.'.room_id', $sets['direct']['room']))
                    ->orWhere(fn ($r) => $r->whereNull($table.'.zone_id')->whereNull($table.'.room_id')->whereIn($table.'.site_id', $sets['direct']['site']));
            }
        });
    }

    public function scope(Builder $query): void
    {
        $user = $this->actor();
        if ($query->getModel() instanceof InventoryBalance || $query->getModel() instanceof InventoryMovement) {
            app(InventoryLocations::class)->scope($query, $user);

            return;
        }
        if (! $this->restricted($user)) {
            return;
        }
        $model = $query->getModel();
        $table = $model->getTable();
        if ($model instanceof Location) {
            $type = $model->kind();
            $query->whereIn($table.'.id', $this->sets($user)[$type === 'zone' ? 'zone' : 'nav_'.$type]);
        } elseif ($model instanceof Document) {
            $query->where(function ($q) use ($table, $user) {
                $q->where(fn ($location) => $this->subject($location, $table, $user))
                    ->orWhere(fn ($strain) => $strain->where($table.'.subject_kind', 'strain')->whereIn($table.'.subject_snapshot->id', Strain::selectRaw('CAST(id AS VARCHAR)')));
            });
        } elseif ($model instanceof Asset) {
            $this->asset($query, $table, $user);
        } elseif ($model instanceof AssetAssignment) {
            $query->where(function ($history) use ($table, $user) {
                $history->where(function ($legacy) use ($table, $user) {
                    $legacy->whereNull($table.'.locations_snapshot');
                    $this->locationColumns($legacy, $table, $user);
                })->orWhereIn($table.'.asset_context_id', $this->allowedContexts($user));
            });
        } elseif ($model instanceof EventEntry) {
            $this->subject($query, $table, $user);
        } elseif ($model instanceof Plant) {
            $this->locationColumns($query, $table, $user);
        } elseif ($model instanceof PlantEvent) {
            $query->whereHas('plant');
            $this->snapshot($query, $table.'.snapshot->location', $user);
            $this->snapshot($query, $table.'.snapshot->previous_location', $user, true);
        } elseif ($model instanceof Sensor) {
            $query->whereHas('device');
        } elseif ($model instanceof RemoteSensorLink || $model instanceof HttpMeasurementSource || $model instanceof MqttMeasurementSource || $model instanceof AlertRule) {
            $query->whereHas('sensor');
        } elseif ($model instanceof Measurement) {
            $query->whereIn($table.'.sensor_id', Sensor::select('sensors.id'));
            $this->measurement($query, $table, $user);
        } elseif ($model instanceof AlertEpisode) {
            $this->episode($query, $table, $user);
        } elseif ($model instanceof EventRevision) {

            $query->where(function ($q) use ($table, $user) {
                $q->where(function ($legacy) use ($table, $user) {
                    $legacy->whereNull($table.'.snapshot->subject->assetContextId');
                    $this->snapshot($legacy, $table.'.snapshot->subject->location', $user);
                })->orWhereIn(DB::raw('CAST('.DB::connection()->getQueryGrammar()->wrap($table.'.snapshot->subject->assetContextId').' AS VARCHAR)'), $this->allowedContexts($user)->select([])->selectRaw('CAST(asset_contexts.id AS VARCHAR)'));
            });

        }
    }
}
