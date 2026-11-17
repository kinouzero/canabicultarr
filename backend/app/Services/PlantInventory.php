<?php

namespace App\Services;

use App\Exceptions\InventoryException;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Plant;
use App\Models\User;
use App\Rules\StockQuantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlantInventory
{
    public static function doseRules(): array
    {
        return ['required', new StockQuantity, 'numeric', 'min:0.001', 'max:1000000'];
    }

    public function validateRecipes(array $stages): void
    {
        $recipes = [];
        foreach ($stages as $index => $stage) {
            foreach ($stage['fertilizer_periods'] ?? [] as $periodIndex => $period) {
                $recipes[] = ["stages.$index.fertilizer_periods.$periodIndex.products", 'fertilizer', $period['products'], $stage['watering']];
            }
            foreach ($stage['actions'] as $actionIndex => $action) {
                $recipes[] = ["stages.$index.actions.$actionIndex.substrates", 'substrate', $action['substrates'] ?? [], true];
            }
        }
        $ids = collect($recipes)->flatMap(fn ($recipe) => array_column($recipe[2], 'inventory_item_id'))->unique();
        $containerIds = collect($stages)->flatMap(fn ($stage) => array_column($stage['actions'], 'container_inventory_item_id'))->filter();
        $items = InventoryItem::whereIn('id', $ids->merge($containerIds))->get()->keyBy('id');
        foreach ($containerIds as $id) {
            $item = $items->get($id);
            if (! $item || $item->type !== 'container' || $item->unit !== 'piece' || ! $item->capacity_liters || $item->is_archived) {
                throw ValidationException::withMessages(['stages' => 'plants.containers.invalid']);
            }
        }
        foreach ($recipes as [$path, $type, $rows, $allowed]) {
            $seen = [];
            foreach ($rows as $row => $recipe) {
                $item = $items->get($recipe['inventory_item_id']);
                if (! $allowed || ! $item || $item->type !== $type || $item->is_archived || in_array($item->id, $seen, true)) {
                    throw ValidationException::withMessages(["$path.$row.inventory_item_id" => 'plants.stock.invalidProduct']);
                }
                $seen[] = $item->id;
            }
        }
    }

    public function options(User $actor, Plant $plant, ?string $actionId = null, ?CarbonImmutable $at = null): array
    {
        $action = $actionId ? collect(LifecycleDefinition::actions($plant->phase))->firstWhere('id', $actionId) : null;
        if ($actionId && ! $action) {
            throw ValidationException::withMessages(['action' => 'plants.errors.actionUnavailable']);
        }
        $schedule = $actionId ? null : app(FertilizerSchedule::class)->forPlant($plant, $at);
        $recipe = $actionId ? ($action['substrates'] ?? []) : $schedule['products'];
        $catalog = $schedule ? collect($schedule['periods'])->flatMap(fn ($period) => array_column($period['products'], 'inventory_item_id'))->unique()->all() : [];
        $type = $actionId ? 'substrate' : 'fertilizer';
        $containerId = $action['container_inventory_item_id'] ?? null;
        $items = InventoryItem::whereIn('id', [...$catalog, ...array_column($recipe, 'inventory_item_id'), ...($containerId ? [$containerId] : [])])
            ->with(['balances' => fn ($query) => $query->where('quantity_units', '>', 0)->with(InventoryBalance::locationRelations())->orderBy('id')])->get()->keyBy('id');

        if ($schedule) {
            unset($schedule['products']);
            $schedule['periods'] = array_map(function ($period) use ($items) {
                $period['products'] = array_map(fn ($product) => [...$product, 'name' => $items->get($product['inventory_item_id'])?->name, 'unit' => $items->get($product['inventory_item_id'])?->unit], $period['products']);

                return $period;
            }, $schedule['periods']);
        }

        $container = $containerId ? $items->get($containerId) : null;
        $containerOption = $containerId ? ['id' => $containerId, 'name' => $container?->name, 'unit' => $container?->unit, 'capacityLiters' => $container?->capacity_liters, 'quantity' => '1', 'available' => $container && $container->type === 'container' && $container->unit === 'piece' && $container->capacity_liters && ! $container->is_archived, 'balances' => $container?->balances->map(fn ($balance) => ['id' => $balance->id, 'quantity' => StockQuantity::format($balance->quantity_units), 'location' => $balance->locationDetails()])->values()->all() ?? []] : null;

        return ['schedule' => $schedule, 'container' => $containerOption, 'currentContainer' => $plant->container_snapshot, 'waterLiters' => $actionId ? null : (LifecycleDefinition::stage($plant->phase)['water_liters'] ?? null), 'canConsume' => $actor->can('create', InventoryItem::class), 'products' => array_map(function ($row) use ($items, $type) {
            $item = $items->get($row['inventory_item_id']);

            return ['id' => $row['inventory_item_id'], 'name' => $item?->name, 'unit' => $item?->unit,
                'quantity' => $row['dose_per_liter'] ?? $row['quantity'], 'available' => $item && $item->type === $type && ! $item->is_archived,
                'balances' => $item?->balances->map(fn ($balance) => ['id' => $balance->id, 'quantity' => StockQuantity::format($balance->quantity_units), 'location' => $balance->locationDetails()])->values()->all() ?? []];
        }, $recipe)];
    }

    private function record(User $actor, InventoryItem $item, array $data): InventoryMovement
    {
        try {
            return app(Inventory::class)->record($actor, $item, $data);
        } catch (InventoryException $error) {
            $message = $error->errorCode === 'inventory.errors.insufficientStock' ? 'plants.stock.insufficientStock' : 'plants.stock.invalidQuantity';
            throw ValidationException::withMessages(['stock' => $message]);
        }
    }

    public function changeContainer(User $actor, Plant $plant, array $data): ?array
    {
        $selection = $data['container'] ?? null;
        if (! $selection) {
            return null;
        }
        $previous = $plant->container_snapshot;
        if ($previous && ! in_array($selection['previous'] ?? null, ['recover', 'discard'], true)) {
            throw ValidationException::withMessages(['container.previous' => 'plants.containers.choosePrevious']);
        }
        $ids = [$selection['inventory_item_id']];
        if ($previous && $selection['previous'] === 'recover') {
            $ids[] = $previous['inventoryItemId'];
        }
        $items = InventoryItem::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $item = $items->get($selection['inventory_item_id']);
        if (! $item || $item->type !== 'container' || $item->unit !== 'piece' || ! $item->capacity_liters || $item->is_archived) {
            throw ValidationException::withMessages(['container' => 'plants.containers.invalid']);
        }
        $balance = $item->balances()->whereKey($selection['balance_id'])->first();
        if (! $balance) {
            throw ValidationException::withMessages(['container' => 'plants.stock.invalidSource']);
        }
        $location = ['id' => $balance->storage_location_id];
        $issue = $this->record($actor, $item, ['kind' => 'issue', 'quantity' => '1', 'version' => $item->version, 'request_id' => (string) Str::uuid(), 'source' => $location, 'reason' => 'Pot change: '.$plant->displayName().' ('.$plant->id.')']);
        $return = null;
        if ($previous && $selection['previous'] === 'recover') {
            $old = $items->get($previous['inventoryItemId']);
            if (! $old || $old->type !== 'container' || $old->unit !== 'piece' || $old->is_archived) {
                throw ValidationException::withMessages(['container' => 'plants.containers.returnUnavailable']);
            }
            $old->refresh();
            $return = $this->record($actor, $old, ['kind' => 'receipt', 'quantity' => '1', 'version' => $old->version, 'request_id' => (string) Str::uuid(), 'destination' => $location, 'reason' => 'Recovered pot: '.$plant->displayName().' ('.$plant->id.')']);
        }
        $current = ['inventoryItemId' => $item->id, 'name' => $item->name, 'capacityLiters' => $item->capacity_liters];
        $plant->container_snapshot = $current;

        return ['current' => $current, 'previous' => $previous, 'previousDisposition' => $previous ? $selection['previous'] : null, 'issueMovementId' => $issue->id, 'returnMovementId' => $return?->id];
    }

    public function consume(User $actor, Plant $plant, array $data, bool $watering = true): array
    {
        $rows = $data[$watering ? 'fertilizers' : 'substrates'] ?? [];
        if (! $rows) {
            return [];
        }
        if ($watering && (! $data['with_products'] || empty($data['water_liters']))) {
            throw ValidationException::withMessages(['fertilizers' => 'plants.stock.invalidUsage']);
        }
        // Stable product lock order and integer thousandths avoid overspending and float drift.
        usort($rows, fn ($a, $b) => strcmp($a['inventory_item_id'], $b['inventory_item_id']));
        $result = [];
        foreach ($rows as $row) {
            $item = InventoryItem::whereKey($row['inventory_item_id'])->lockForUpdate()->first();
            if (! $item || $item->type !== ($watering ? 'fertilizer' : 'substrate') || $item->is_archived || $item->unit !== $row['unit']) {
                throw ValidationException::withMessages(['fertilizers' => 'plants.stock.invalidProduct']);
            }
            Gate::forUser($actor)->authorize('update', $item);
            $balance = $item->balances()->whereKey($row['balance_id'])->first();
            if (! $balance) {
                throw ValidationException::withMessages(['fertilizers' => 'plants.stock.invalidSource']);
            }
            $amount = $watering ? intdiv(StockQuantity::toUnits($row['dose_per_liter']) * StockQuantity::toUnits($data['water_liters']) + 500, 1000) : StockQuantity::toUnits($row['quantity']);
            if ($amount < 1 || $amount > StockQuantity::MAX_UNITS) {
                throw ValidationException::withMessages(['fertilizers' => 'plants.stock.invalidQuantity']);
            }
            $movement = $this->record($actor, $item, [
                'kind' => 'issue', 'quantity' => StockQuantity::format($amount), 'version' => $item->version,
                'request_id' => (string) Str::uuid(), 'reason' => ($watering ? 'Watering' : $data['action']).': '.$plant->displayName().' ('.$plant->id.')',
                'source' => ['id' => $balance->storage_location_id],
            ]);
            $result[] = ['inventoryItemId' => $item->id, 'name' => $item->name, 'unit' => $item->unit,
                ...($watering ? ['dosePerLiter' => StockQuantity::format(StockQuantity::toUnits($row['dose_per_liter']))] : []),
                'quantity' => StockQuantity::format($amount), 'movementId' => $movement->id];
        }

        return $result;
    }
}
