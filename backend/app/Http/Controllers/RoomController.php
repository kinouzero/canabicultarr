<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListLocationsRequest;
use App\Http\Requests\SaveLocationRequest;
use App\Http\Resources\LocationResource;
use App\Models\Room;
use App\Models\Site;
use App\Services\LocationAdministration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RoomController extends Controller
{
    public function index(ListLocationsRequest $request, Site $site, LocationAdministration $locations)
    {
        return LocationResource::collection($locations->search(Room::where('site_id', $site->id), $request->validated()));
    }

    public function store(SaveLocationRequest $request, Site $site, LocationAdministration $locations)
    {
        return (new LocationResource($locations->create($request->user(), new Room, $request->validated(), $site)))
            ->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Site $site, Room $room, LocationAdministration $locations)
    {
        Gate::authorize('delete', $room);
        $locations->delete($request->user(), $room);

        return response()->noContent();
    }

    public function update(SaveLocationRequest $request, Site $site, Room $room, LocationAdministration $locations)
    {
        return new LocationResource($locations->update($request->user(), $room, $request->validated()));
    }
}
