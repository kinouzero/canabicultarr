<?php

namespace App\Http\Controllers;

use App\Enums\DelegatedPermission;
use App\Exceptions\InventoryException;
use App\Http\Requests\ListInventoryRequest;
use App\Http\Requests\SaveStorageLocationRequest;
use App\Http\Resources\StorageLocationResource;
use App\Models\InventoryBalance;
use App\Models\StorageLocation;
use App\Models\User;
use App\Services\CollectionPagination;
use App\Services\InventoryLocations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StorageLocationController extends Controller
{
    public function index(ListInventoryRequest $request)
    {
        $query = StorageLocation::query()->orderBy('name')->orderBy('id');
        if ($request->input('state', 'active') !== 'all') {
            $query->where('is_archived', $request->input('state') === 'archived');
        }
        if ($search = trim($request->input('search', ''))) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower($search)).'%';
            $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]);
        }

        return StorageLocationResource::collection($query->paginate(CollectionPagination::size($request->validated())))
            ->additional(['canManage' => $request->user()->hasOperationalPermission(DelegatedPermission::Inventory) && ! app(InventoryLocations::class)->restricted($request->user())]);
    }

    public function show(StorageLocation $location)
    {
        return new StorageLocationResource($location);
    }

    public function store(SaveStorageLocationRequest $request)
    {
        return $this->save($request);
    }

    public function update(SaveStorageLocationRequest $request, StorageLocation $location)
    {
        return $this->save($request, $location);
    }

    private function save(SaveStorageLocationRequest $request, ?StorageLocation $target = null)
    {
        $location = DB::transaction(function () use ($request, $target) {
            $actor = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if ($actor->session_version !== $request->user()->session_version || ! $actor->hasOperationalPermission(DelegatedPermission::Inventory)) {
                abort(403);
            }
            app(InventoryLocations::class)->assertGlobal($actor);
            $location = $target ? StorageLocation::whereKey($target->id)->lockForUpdate()->firstOrFail() : new StorageLocation;
            $location->fill($request->validated());
            $location->name = trim($location->name);
            if ($location->name === '') {
                throw ValidationException::withMessages(['name' => 'inventory.validation.nameInvalid']);
            }
            if ($location->is_archived && InventoryBalance::withoutGlobalScopes()->where('storage_location_id', $location->id)->where('quantity_units', '>', 0)->exists()) {
                throw new InventoryException('inventory.errors.stockRemaining');
            }
            $location->save();
            Log::notice('inventory.location_saved', ['location_uuid' => $location->id, 'actor_uuid' => $actor->uuid]);

            return $location;
        });

        return (new StorageLocationResource($location))->response()->setStatusCode($target ? 200 : 201);
    }
}
