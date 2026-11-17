<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListLocationsRequest;
use App\Http\Requests\SaveLocationRequest;
use App\Http\Resources\LocationResource;
use App\Models\Site;
use App\Services\LocationAdministration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SiteController extends Controller
{
    public function index(ListLocationsRequest $request, LocationAdministration $locations)
    {
        return LocationResource::collection($locations->search(Site::query(), $request->validated()));
    }

    public function store(SaveLocationRequest $request, LocationAdministration $locations)
    {
        return (new LocationResource($locations->create($request->user(), new Site, $request->validated(), null)))
            ->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Site $site, LocationAdministration $locations)
    {
        Gate::authorize('delete', $site);
        $locations->delete($request->user(), $site);

        return response()->noContent();
    }

    public function update(SaveLocationRequest $request, Site $site, LocationAdministration $locations)
    {
        return new LocationResource($locations->update($request->user(), $site, $request->validated()));
    }
}
