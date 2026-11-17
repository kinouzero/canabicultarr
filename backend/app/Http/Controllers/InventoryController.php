<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListInventoryRequest;
use App\Http\Requests\SaveInventoryItemRequest;
use App\Http\Requests\StockMovementRequest;
use App\Http\Resources\InventoryBalanceResource;
use App\Http\Resources\InventoryItemResource;
use App\Http\Resources\InventoryMovementResource;
use App\Models\InventoryItem;
use App\Services\Inventory;
use App\Services\InventoryLocations;
use Illuminate\Support\Facades\Gate;

class InventoryController extends Controller
{
    public function index(ListInventoryRequest $request, Inventory $inventory)
    {
        return InventoryItemResource::collection($inventory->search($request->validated()))->additional(['canEditCatalog' => $request->user()->can('create', InventoryItem::class) && ! app(InventoryLocations::class)->restricted($request->user())]);
    }

    public function store(SaveInventoryItemRequest $request, Inventory $inventory)
    {
        return (new InventoryItemResource($inventory->save($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function show(InventoryItem $item)
    {
        Gate::authorize('view', $item);

        return new InventoryItemResource($item);
    }

    public function update(SaveInventoryItemRequest $request, InventoryItem $item, Inventory $inventory)
    {
        return new InventoryItemResource($inventory->save($request->user(), $request->validated(), $item));
    }

    public function balances(ListInventoryRequest $request, InventoryItem $item, Inventory $inventory)
    {
        Gate::authorize('view', $item);

        return InventoryBalanceResource::collection($inventory->balances($item, $request->validated()));
    }

    public function movements(ListInventoryRequest $request, InventoryItem $item, Inventory $inventory)
    {
        Gate::authorize('view', $item);

        return InventoryMovementResource::collection($inventory->movements($item, $request->validated()));
    }

    public function record(StockMovementRequest $request, InventoryItem $item, Inventory $inventory)
    {
        return (new InventoryMovementResource($inventory->record($request->user(), $item, $request->validated())))->response()->setStatusCode(201);
    }
}
