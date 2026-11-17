<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class InventoryLocations
{
    public function restricted(?User $actor = null): bool
    {
        $actor ??= app(LocationAccess::class)->actor();

        return $actor && ! $actor->isAdministrator() && $actor->inventory_location_access !== null;
    }

    public function allows(User $actor, string $id): bool
    {
        return $actor->is_active && (! $this->restricted($actor) || in_array($id, $actor->inventory_location_access, true));
    }

    public function assertGlobal(User $actor): void
    {
        if (! $actor->is_active || $this->restricted($actor)) {
            throw new AuthorizationException;
        }
    }

    public function resolve(User $actor, string $id): StorageLocation
    {
        $location = StorageLocation::withoutGlobalScopes()->whereKey($id)->lockForUpdate()->firstOrFail();
        if (! $this->allows($actor, $location->id)) {
            abort(404);
        }

        return $location;
    }

    public function normalize(?array $ids): ?array
    {
        if ($ids === null) {
            return null;
        }
        $ids = array_values(array_unique(array_map('strtolower', $ids)));
        if (StorageLocation::withoutGlobalScopes()->whereKey($ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['inventory_location_access' => 'storageLocations.invalid']);
        }
        sort($ids);

        return $ids;
    }

    public function describe(User $actor): ?array
    {
        return ! $this->restricted($actor) ? null : StorageLocation::withoutGlobalScopes()->whereKey($actor->inventory_location_access)->orderBy('name')->get(['id', 'name'])->toArray();
    }

    public function scope($query, ?User $actor = null): void
    {
        $actor ??= app(LocationAccess::class)->actor();
        if (! $this->restricted($actor)) {
            return;
        }
        $model = $query->getModel();
        $ids = $actor->inventory_location_access;
        if ($model instanceof StorageLocation) {
            $query->whereIn('storage_locations.id', $ids);
        } elseif ($model instanceof InventoryBalance) {
            $query->whereIn('inventory_balances.storage_location_id', $ids);
        } elseif ($model instanceof InventoryMovement) {
            $query->where(fn ($q) => $q->whereNotNull('source_balance_id')->orWhereNotNull('destination_balance_id'));
            $balances = InventoryBalance::withoutGlobalScopes()->select('id')->whereIn('storage_location_id', $ids);
            foreach (['source', 'destination'] as $side) {
                $query->where(fn ($q) => $q->whereNull($side.'_balance_id')->orWhereIn($side.'_balance_id', $balances));
            }
        }
    }
}
