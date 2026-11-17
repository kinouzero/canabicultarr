<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListAssetsRequest;
use App\Http\Requests\SaveAssetRequest;
use App\Http\Resources\AssetAssignmentResource;
use App\Http\Resources\AssetResource;
use App\Http\Resources\LinkedPlantResource;
use App\Models\Asset;
use App\Services\AssetAdministration;
use App\Services\AssetLiveReadings;
use App\Services\CollectionPagination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AssetController extends Controller
{
    public function index(ListAssetsRequest $request, AssetAdministration $assets)
    {
        return AssetResource::collection($assets->search($request->validated()));
    }

    public function store(SaveAssetRequest $request, AssetAdministration $assets)
    {
        return (new AssetResource($assets->save($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function liveReadings(Request $request, Asset $asset, AssetLiveReadings $readings)
    {
        Gate::authorize('view', $asset);
        $data = $request->validate(['sensor_ids' => ['required', 'array', 'min:1', 'max:10'], 'sensor_ids.*' => ['required', 'uuid', 'distinct']]);

        return ['data' => $readings->read($asset, $data['sensor_ids'])];
    }

    public function show(Asset $asset)
    {
        Gate::authorize('view', $asset);

        return new AssetResource($asset->load(Asset::assignmentRelations())->loadCount(['plants', 'sensors', 'sensors as enabled_channels_count' => fn ($query) => $query->where('is_enabled', true)])->loadExists(Asset::INTEGRATION_RELATIONS));
    }

    public function update(SaveAssetRequest $request, Asset $asset, AssetAdministration $assets)
    {
        return new AssetResource($assets->save($request->user(), $request->validated(), $asset));
    }

    public function plants(ListAssetsRequest $request, Asset $asset)
    {
        Gate::authorize('view', $asset);
        $criteria = $request->validated();
        $plants = $asset->plants()->with(Asset::plantRelations())
            ->paginate(CollectionPagination::size($criteria), ['*'], 'page', $criteria['page'] ?? 1);

        return LinkedPlantResource::collection($plants);
    }

    public function assignments(ListAssetsRequest $request, Asset $asset, AssetAdministration $assets)
    {
        Gate::authorize('view', $asset);

        return AssetAssignmentResource::collection($assets->assignments($asset, $request->validated()));
    }
}
