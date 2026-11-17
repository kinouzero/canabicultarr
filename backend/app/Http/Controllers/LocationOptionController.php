<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListLocationsRequest;
use App\Http\Resources\LocationOptionResource;
use App\Http\Resources\LocationResource;
use App\Http\Resources\PlantCardResource;
use App\Models\Plant;
use App\Models\Room;
use App\Models\Site;
use App\Models\Zone;
use App\Services\CollectionPagination;
use App\Services\LocationAccess;
use App\Services\LocationOptions;
use App\Services\PlantMeasurements;
use App\Services\PlantWateringNeeds;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class LocationOptionController extends Controller
{
    public function index(ListLocationsRequest $request, LocationOptions $options)
    {
        return LocationOptionResource::collection($options->search($request->validated()));
    }

    public function show(ListLocationsRequest $request, string $kind, string $id, LocationOptions $options, LocationAccess $access)
    {
        abort_unless($access->allows($request->user(), ['type' => $kind, 'id' => $id]), 404);

        return new LocationOptionResource($options->find($kind, $id));
    }

    public function plants(Request $request, string $kind, string $id, LocationOptions $options, LocationAccess $access)
    {
        Gate::authorize('viewAny', Plant::class);
        abort_unless($access->allows($request->user(), ['type' => $kind, 'id' => $id]), 404);
        $options->find($kind, $id);
        $criteria = $request->validate(['status' => ['sometimes', Rule::in(['active', 'planned', 'all'])], 'page' => ['sometimes', 'integer', 'between:1,100000'], 'per_page' => CollectionPagination::rules()], ['*' => 'plants.validation.invalid']);
        $query = Plant::with(['strain', 'photoDocument', 'recentWaterings', ...Plant::locationRelations()])->where('phase', '!=', 'archived');
        if (($criteria['status'] ?? 'all') === 'planned') {
            $query->where('phase', 'planned');
        } elseif (($criteria['status'] ?? 'all') === 'active') {
            $query->where('phase', '!=', 'planned');
        }
        $query->where(function ($plants) use ($kind, $id) {
            $plants->where($kind.'_id', $id);
            if ($kind === 'site') {
                $plants->orWhereHas('room', fn ($rooms) => $rooms->where('site_id', $id))
                    ->orWhereHas('zone.room', fn ($rooms) => $rooms->where('site_id', $id));
            } elseif ($kind === 'room') {
                $plants->orWhereHas('zone', fn ($zones) => $zones->where('room_id', $id));
            }
        });

        $records = $query->orderBy('name_key')->orderBy('id')->paginate(CollectionPagination::size($criteria));
        app(PlantWateringNeeds::class)->prepare($records->getCollection());
        app(PlantMeasurements::class)->prepare($records->getCollection());

        return PlantCardResource::collection($records);
    }

    public function detail(Request $request, string $kind, string $id, LocationAccess $access)
    {
        abort_unless($access->allows($request->user(), ['type' => $kind, 'id' => $id]), 404);
        $class = match ($kind) {
            'site' => Site::class,
            'room' => Room::class,
            'zone' => Zone::class,
        };
        $record = $class::with('photoDocument')->findOrFail($id);
        Gate::authorize('view', $record);
        if ($relation = $record->childRelation()) {
            $record->loadCount($relation);
        }

        return new LocationResource($record);
    }
}
