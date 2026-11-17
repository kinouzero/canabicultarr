<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListLocationsRequest;
use App\Http\Requests\SaveLocationRequest;
use App\Http\Resources\LocationResource;
use App\Models\Room;
use App\Models\Site;
use App\Models\Zone;
use App\Services\LocationAdministration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ZoneController extends Controller
{
    public function index(ListLocationsRequest $request, Site $site, Room $room, LocationAdministration $locations)
    {
        return LocationResource::collection($locations->search(Zone::where('room_id', $room->id), $request->validated()));
    }

    public function store(SaveLocationRequest $request, Site $site, Room $room, LocationAdministration $locations)
    {
        return (new LocationResource($locations->create($request->user(), new Zone, $request->validated(), $room)))
            ->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Site $site, Room $room, Zone $zone, LocationAdministration $locations)
    {
        Gate::authorize('delete', $zone);
        $locations->delete($request->user(), $zone);

        return response()->noContent();
    }

    public function update(SaveLocationRequest $request, Site $site, Room $room, Zone $zone, LocationAdministration $locations)
    {
        return new LocationResource($locations->update($request->user(), $zone, $request->validated()));
    }
}
