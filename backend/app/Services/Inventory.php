<?php

namespace App\Services;

use App\Exceptions\InventoryException;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Strain;
use App\Models\User;
use App\Rules\StockQuantity;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Inventory
{
    public const SORT_COLUMNS = ['name' => 'name', 'created' => 'created_at', 'quantity' => 'visible_quantity', 'reference' => 'sku'];

    public function search(array $criteria)
    {
        $query = InventoryItem::query();
        if (! empty($criteria['type'])) {
            $query->where('type', $criteria['type']);
        }
        $state = $criteria['state'] ?? 'active';
        if ($state !== 'all') {
            $query->where('is_archived', $state === 'archived');
        }
        if (! empty($criteria['low_stock'])) {
            if (app(InventoryLocations::class)->restricted()) {
                $query->whereRaw('1=0');
            } else {
                $query->whereNotNull('threshold_units')->whereColumn('total_units', '<=', 'threshold_units');
            }
        }
        if (($search = trim($criteria['search'] ?? '')) !== '') {
            if (Str::isUuid($search)) {
                $query->whereKey($search);
            } else {
                $query->whereRaw("search_text LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower($search)).'%']);
            }
        }

        if (str_starts_with($criteria['sort'] ?? '', 'quantity_')) {
            $query->select('inventory_items.*');
            if (app(InventoryLocations::class)->restricted()) {
                $query->selectSub(InventoryBalance::selectRaw('COALESCE(SUM(quantity_units), 0)')->whereColumn('inventory_item_id', 'inventory_items.id'), 'visible_quantity');
            } else {
                $query->selectRaw('total_units AS visible_quantity');
            }
        }

        return CollectionSorting::apply($query, $criteria['sort'] ?? null, self::SORT_COLUMNS)->withSum('balances', 'quantity_units')->paginate(CollectionPagination::size($criteria), ['*'], 'page', $criteria['page'] ?? 1);
    }

    public function save(User $actor, array $data, ?InventoryItem $target = null): InventoryItem
    {
        app(InventoryLocations::class)->assertGlobal($actor);
        $creating = $target === null;
        try {
            [$item,$fields] = DB::transaction(function () use ($actor, $data, $target, $creating) {
                $current = $this->actor($actor);
                app(InventoryLocations::class)->assertGlobal($current);
                $item = $creating ? new InventoryItem : InventoryItem::whereKey($target->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($current)->authorize($creating ? 'create' : 'update', $creating ? InventoryItem::class : $item);
                if (! $creating && (int) $data['version'] !== $item->version) {
                    throw new InventoryException('inventory.errors.conflict');
                }
                $item->fill(Arr::only($data, ['name', 'sku', 'description', 'type', 'unit', 'decimal_places', 'default_dose_per_liter', 'capacity_liters', 'is_archived']));
                if ($creating && ! array_key_exists('decimal_places', $data)) {
                    $item->decimal_places = $item->unit === 'piece' ? 0 : 3;
                }
                if (array_key_exists('threshold', $data)) {
                    $item->threshold_units = $data['threshold'] === null ? null : $this->quantity($data['threshold']);
                }
                if ($item->type === 'seeds' && $item->unit !== 'piece') {
                    throw ValidationException::withMessages(['unit' => ['inventory.validation.seedUnit']]);
                }
                if (! $creating && $item->isDirty(['type', 'unit']) && Strain::where('seed_inventory_item_id', $item->id)->exists() && ($item->type !== 'seeds' || $item->unit !== 'piece')) {
                    throw ValidationException::withMessages(['type' => ['inventory.validation.linkedSeeds']]);
                }
                if ($item->capacity_liters !== null && ($item->type !== 'container' || $item->unit !== 'piece')) {
                    throw ValidationException::withMessages(['capacity_liters' => 'inventory.validation.capacityInvalid']);
                }
                if ($item->default_dose_per_liter !== null && ($item->type !== 'fertilizer' || ! in_array($item->unit, ['ml', 'l', 'g', 'kg'], true))) {
                    throw ValidationException::withMessages(['default_dose_per_liter' => 'inventory.validation.doseInvalid']);
                }
                if ($item->unit_locked && $item->isDirty('unit')) {
                    throw new InventoryException('inventory.errors.unitLocked');
                }
                if ($item->unit === 'piece' && ($item->threshold_units ?? 0) % 1000 !== 0) {
                    throw ValidationException::withMessages(['threshold' => ['inventory.validation.wholePieces']]);
                }
                if ($item->is_archived && $item->total_units !== 0) {
                    throw new InventoryException('inventory.errors.stockRemaining');
                }
                $fields = array_values(array_intersect(array_keys($item->getDirty()), ['name', 'sku', 'description', 'type', 'unit', 'decimal_places', 'default_dose_per_liter', 'capacity_liters', 'threshold_units', 'is_archived']));
                if ($creating || $fields) {
                    $item->version = $creating ? 1 : $item->version + 1;
                    $item->save();
                }

                return [$item, $fields];
            });
        } catch (UniqueConstraintViolationException) {
            throw new InventoryException('inventory.errors.skuTaken');
        }
        if ($creating || $fields) {
            Log::notice($creating ? 'inventory.item_created' : 'inventory.item_updated', ['actor_uuid' => $actor->uuid, 'item_uuid' => $item->id, 'fields' => $fields]);
        }

        return $item;
    }

    private function actor(User $actor): User
    {
        $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        if (! $current->is_active || $current->session_version !== $actor->session_version) {
            throw new AuthenticationException;
        }

        return $current;
    }

    private function quantity(mixed $value): int
    {
        $error = null;
        (new StockQuantity)->validate('quantity', $value, function ($key) use (&$error) {
            $error = $key;
        });
        if ($error) {
            throw ValidationException::withMessages(['quantity' => [$error]]);
        }

        return StockQuantity::toUnits($value);
    }

    private function balance(InventoryItem $item, array $location): InventoryBalance
    {
        return $item->balances()->withoutGlobalScopes()->firstOrCreate(['storage_location_id' => $location['id']], ['quantity_units' => 0]);
    }

    public function record(User $actor, InventoryItem $target, array $data): InventoryMovement
    {
        [$movement,$created] = DB::transaction(function () use ($actor, $target, $data) {
            $current = $this->actor($actor);
            $item = InventoryItem::whereKey($target->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($current)->authorize('update', $item);
            $amount = $this->quantity($data['quantity']);
            $kind = $data['kind'];
            $normalizeLocation = fn ($value) => $value === null ? null : ['id' => strtolower($value['id'])];
            $source = $normalizeLocation($data['source'] ?? null);
            $destination = $normalizeLocation($data['destination'] ?? null);
            $locationIds = array_values(array_unique(array_filter([$source['id'] ?? null, $destination['id'] ?? null])));
            sort($locationIds);
            $locations = [];
            foreach ($locationIds as $id) {
                $locations[] = app(InventoryLocations::class)->resolve($current, $id);
            }
            $requestId = strtolower($data['request_id']);
            $reason = trim($data['reason']);
            $hash = hash('sha256', json_encode([$current->uuid, $kind, $amount, $source['id'] ?? null, $destination['id'] ?? null, $reason, (int) $data['version']], JSON_THROW_ON_ERROR));
            $existing = $item->movements()->withoutGlobalScopes()->where('request_id', $requestId)->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw new InventoryException('inventory.errors.requestConflict');
                }

                return [$existing, false];
            }
            foreach ($locations as $location) {
                if ($location->is_archived) {
                    throw ValidationException::withMessages(['destination' => 'inventory.validation.locationInvalid']);
                }
            }
            if ((int) $data['version'] !== $item->version) {
                throw new InventoryException('inventory.errors.conflict');
            }
            if ($item->is_archived) {
                throw new InventoryException('inventory.errors.archived');
            }
            if (! in_array($kind, ['receipt', 'issue', 'transfer', 'count'], true) || ($kind !== 'count' && $amount === 0)) {
                throw ValidationException::withMessages(['quantity' => ['inventory.validation.quantityPositive']]);
            }
            if ($item->unit === 'piece' && $amount % 1000 !== 0) {
                throw ValidationException::withMessages(['quantity' => ['inventory.validation.wholePieces']]);
            }
            if (($source !== null) !== in_array($kind, ['issue', 'transfer'], true) || ($destination !== null) !== in_array($kind, ['receipt', 'transfer', 'count'], true) || $reason === '' || mb_strlen($reason) > 500) {
                throw ValidationException::withMessages(['source' => ['inventory.validation.locationInvalid']]);
            }
            if ($source && $destination && $source['id'] === $destination['id']) {
                throw ValidationException::withMessages(['destination' => ['inventory.validation.differentLocations']]);
            }
            $from = $source ? $this->balance($item, $source) : null;
            $to = $destination ? $this->balance($item, $destination) : null;
            $beforeFrom = $from?->quantity_units;
            $beforeTo = $to?->quantity_units;
            if ($from && $beforeFrom < $amount) {
                throw new InventoryException('inventory.errors.insufficientStock');
            }
            $total = match ($kind) {
                'receipt' => $item->total_units + $amount,'issue' => $item->total_units - $amount,'transfer' => $item->total_units,'count' => $item->total_units - $beforeTo + $amount
            };
            if ($total > StockQuantity::MAX_UNITS) {
                throw new InventoryException('inventory.errors.quantityLimit');
            }
            if ($from) {
                $from->quantity_units -= $amount;
                $from->save();
            }
            if ($to) {
                $to->quantity_units = $kind === 'count' ? $amount : $beforeTo + $amount;
                $to->save();
            }
            $item->forceFill(['total_units' => $total, 'unit_locked' => true, 'version' => $item->version + 1])->save();
            $movement = $item->movements()->create(['request_id' => $requestId, 'request_hash' => $hash, 'version' => $item->version, 'kind' => $kind, 'quantity_units' => $amount, 'reason' => $reason, 'item_name' => $item->name, 'unit' => $item->unit,
                'source_balance_id' => $from?->id, 'destination_balance_id' => $to?->id, 'source_snapshot' => $from?->locationDetails(), 'destination_snapshot' => $to?->locationDetails(),
                'source_before' => $beforeFrom, 'source_after' => $from?->quantity_units, 'destination_before' => $beforeTo, 'destination_after' => $to?->quantity_units, 'total_after' => $total, 'recorded_by' => $current->id, 'actor_uuid' => $current->uuid, 'recorded_at' => now()]);

            return [$movement, true];
        });
        if ($created) {
            Log::notice('inventory.movement_recorded', ['actor_uuid' => $actor->uuid, 'item_uuid' => $target->id, 'movement_uuid' => $movement->id, 'kind' => $movement->kind, 'version' => $movement->version]);
        }

        return $movement->load('actor:id,uuid,name');
    }

    public function balances(InventoryItem $item, array $criteria)
    {
        return $item->balances()->with(InventoryBalance::locationRelations())->orderBy('id')->paginate(CollectionPagination::size($criteria), ['*'], 'page', $criteria['page'] ?? 1);
    }

    public function movements(InventoryItem $item, array $criteria)
    {
        return $item->movements()->with('actor:id,uuid,name')->orderByDesc('version')->paginate(CollectionPagination::size($criteria), ['*'], 'page', $criteria['page'] ?? 1);
    }
}
